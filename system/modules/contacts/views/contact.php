<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_contacts_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view this contact.</div>';
    return;
}

metis_contacts_ensure_schema();

$cid = isset( metis_request_get()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_get()['cid'] ) ) : '';
if ( $cid === '' ) {
    echo '<div class="metis-alert metis-alert-error">Missing contact CID.</div>';
    return;
}

$contact = \Metis\Modules\Contacts\ContactReadService::getByCid( $cid );

if ( ! $contact ) {
    echo '<div class="metis-alert metis-alert-error">Contact not found.</div>';
    return;
}

$contact_full_name = trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) );
metis_set_page_title( $contact_full_name ?: ( $contact->email ?? $cid ) );

$contact_id = (int) $contact->id;
$did = (string) ( $contact->did ?? '' );
$is_donor = $did !== '';
$total_contributions = 0.0;
$donor_profile_url = '';
$last_donation = null;

if ( $is_donor ) {
    $donor_summary = \Metis\Modules\Contacts\ContactReadService::donorSummary( $did );
    $total_contributions = (float) ( $donor_summary['total_contributions'] ?? 0 );
    $last_donation = $donor_summary['last_donation'] ?? null;
}

if ( $is_donor ) {
    if ( function_exists( 'metis_donations_base_url' ) ) {
        $donor_profile_url = metis_trailingslashit( metis_donations_base_url() ) . 'donor/?id=' . rawurlencode( $did );
    } else {
        $donor_profile_url = metis_portal_url( 'donations', 'donor' ) . '?id=' . rawurlencode( $did );
    }
}

$details_rows = \Metis\Modules\Contacts\ContactReadService::detailRows( $cid, $contact_id, $did );
$details = ! empty( $details_rows ) ? $details_rows[0] : null;

$additional_emails = [];
$relationships = [];

foreach ( $details_rows as $detail_row ) {
    if ( ! empty( $detail_row->additional_emails_json ) ) {
        $decoded = json_decode( (string) $detail_row->additional_emails_json, true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $entry ) {
                $candidate = '';
                if ( is_string( $entry ) || is_numeric( $entry ) ) {
                    $candidate = (string) $entry;
                } elseif ( is_array( $entry ) ) {
                    $candidate = (string) ( $entry['email'] ?? '' );
                }
                if ( class_exists( 'Normalizer' ) ) {
                    $normalized_candidate = Normalizer::normalize( $candidate, Normalizer::FORM_KC );
                    if ( is_string( $normalized_candidate ) ) {
                        $candidate = $normalized_candidate;
                    }
                }
                $candidate = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\p{Cf}]/u', '', (string) $candidate );
                $candidate = preg_replace( '/\s+/u', '', (string) $candidate );
                $candidate = strtolower( trim( metis_email_clean( $candidate ) ) );
                $primary_contact_email = strtolower( trim( (string) ( $contact->email ?? '' ) ) );
                if ( $candidate !== '' && $candidate !== $primary_contact_email && metis_email_is_valid( $candidate ) ) {
                    $additional_emails[] = $candidate;
                }
            }
        }
    }

    if ( ! empty( $detail_row->relationships_json ) ) {
        $decoded = json_decode( (string) $detail_row->relationships_json, true );
        if ( is_array( $decoded ) ) {
            $relationships = array_merge( $relationships, $decoded );
        }
    }
}
$additional_emails = array_values( array_unique( $additional_emails ) );

// Also include reverse relationships (other contacts pointing to this CID).
if ( ! empty( $details_rows ) ) {
    $incoming = \Metis\Modules\Contacts\ContactReadService::incomingRelationships( $cid, $contact_id );
    foreach ( $incoming as $inc ) {
        $decoded_incoming = json_decode( (string) $inc->relationships_json, true );
        if ( ! is_array( $decoded_incoming ) ) {
            continue;
        }

        $source_name = trim( (string) $inc->source_first_name . ' ' . (string) $inc->source_last_name );
        if ( $source_name === '' ) {
            $source_name = (string) $inc->source_email;
        }

        foreach ( $decoded_incoming as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $target_cid = (string) ( $entry['related_contact_cid'] ?? $entry['related_contact_id'] ?? '' );
            if ( $target_cid !== $cid ) {
                continue;
            }
            $relationships[] = [
                'related_contact_cid' => (string) $inc->source_cid,
                'name'                => $source_name,
                'relation_type'       => (string) ( $entry['relation_type'] ?? '' ),
                'notes'               => (string) ( $entry['notes'] ?? '' ),
            ];
        }
    }
}

// De-duplicate by CID + relationship type + notes.
$deduped_relationships = [];
$seen_relationships = [];
foreach ( $relationships as $entry ) {
    if ( ! is_array( $entry ) ) {
        $entry = [ 'name' => (string) $entry ];
    }
    $r_cid = (string) ( $entry['related_contact_cid'] ?? $entry['related_contact_id'] ?? '' );
    $r_type = strtolower( trim( (string) ( $entry['relation_type'] ?? '' ) ) );
    $r_notes = strtolower( trim( (string) ( $entry['notes'] ?? '' ) ) );
    $key = $r_cid . '|' . $r_type . '|' . $r_notes;
    if ( isset( $seen_relationships[ $key ] ) ) {
        continue;
    }
    $seen_relationships[ $key ] = true;
    $deduped_relationships[] = $entry;
}
$relationships = $deduped_relationships;

$notes = \Metis\Modules\Contacts\ContactReadService::notes( $cid, $contact_id, $did );

$all_contacts = \Metis\Modules\Contacts\ContactReadService::allContactsExcept( $contact_id );

$newsletter_lists = \Metis\Modules\Contacts\ContactReadService::newsletterLists();
$newsletter_selected_ids = \Metis\Modules\Contacts\ContactReadService::newsletterSelectedIds( $contact_id );
$newsletter_lists_by_id = [];
foreach ( $newsletter_lists as $list_row ) {
    $lid = (int) ( $list_row->id ?? 0 );
    if ( $lid > 0 ) {
        $newsletter_lists_by_id[ $lid ] = (string) ( $list_row->name ?? '' );
    }
}
$newsletter_selected_names = [];
foreach ( $newsletter_selected_ids as $sel_id ) {
    if ( isset( $newsletter_lists_by_id[ $sel_id ] ) ) {
        $newsletter_selected_names[] = [ 'id' => $sel_id, 'name' => $newsletter_lists_by_id[ $sel_id ] ];
    }
}

$full_name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
$full_name = $full_name !== '' ? $full_name : '(No name)';

$contact_name_by_cid = [
    (string) $cid => $full_name,
];
foreach ( $all_contacts as $c ) {
    $c_cid = (string) ( $c->cid ?? '' );
    if ( $c_cid === '' ) continue;
    $c_name = trim( (string) ( $c->first_name ?? '' ) . ' ' . (string) ( $c->last_name ?? '' ) );
    if ( $c_name === '' ) {
        $c_name = (string) ( $c->email ?? '' );
    }
    if ( $c_name === '' ) {
        $c_name = 'Related Contact';
    }
    $contact_name_by_cid[ $c_cid ] = $c_name;
}

$relation_types = [
    'Spouse',
    'Family',
    'Friend',
    'Colleague',
    'Board Member',
    'Volunteer',
    'Donor',
    'Other',
];

$phone_display = (string) ( $details->phone ?? '' );
$preferred_name_display = (string) ( $details->preferred_name ?? '' );
$preferred_contact_method_display = (string) ( $details->preferred_contact_method ?? '' );
$address_display = (string) ( $details->address ?? '' );
$city_display = (string) ( $details->city ?? '' );
$state_display = (string) ( $details->state ?? '' );
$zip_display = (string) ( $details->zip ?? '' );
$birthday_display = (string) ( $details->birthday ?? '' );
$spouse_name_display = (string) ( $details->spouse_name ?? '' );
$household_id_display = (string) ( $details->household_id ?? '' );
$source_code_display = (string) ( $details->source_code ?? '' );
$first_contacted_display = (string) ( $details->first_contacted ?? '' );
$staff_owner_display = (string) ( $details->staff_owner ?? '' );
$do_not_contact_display = ! empty( $details->do_not_contact ) ? 'Yes' : 'No';
$volunteer_status_display = ! empty( $details->volunteer_status ) ? 'Yes' : 'No';
$anonymous_donor_display = ! empty( $details->anonymous_donor ) ? 'Yes' : 'No';
$address_line_1 = trim( $address_display );
$city_state_zip_parts = [];
if ( trim( $city_display ) !== '' ) {
    $city_state_zip_parts[] = trim( $city_display );
}
$state_zip = trim( trim( $state_display ) . ( trim( $zip_display ) !== '' ? ' ' . trim( $zip_display ) : '' ) );
if ( $state_zip !== '' ) {
    $city_state_zip_parts[] = $state_zip;
}
$address_line_2 = implode( ', ', $city_state_zip_parts );
$has_extra_details = (
    $preferred_name_display !== '' ||
    $preferred_contact_method_display !== '' ||
    $address_line_1 !== '' ||
    $address_line_2 !== '' ||
    $birthday_display !== '' ||
    $household_id_display !== '' ||
    $first_contacted_display !== '' ||
    ! empty( $details->do_not_contact ) ||
    ! empty( $details->volunteer_status ) ||
    ! empty( $details->anonymous_donor )
);

$inline_email_options = array_values( array_unique( array_filter( array_merge( [ (string) $contact->email ], $additional_emails ) ) ) );
$us_states = [
    'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA',
    'HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
    'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
    'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
    'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
];
?>

<div class="metis-contact-detail" data-contact-cid="<?php echo metis_escape_attr( (string) ( $contact->cid ?? '' ) ); ?>">
    <div class="metis-space-between" style="margin-bottom: 14px;">
        <div>
            <h1 class="metis-page-title metis-inline-editable" data-field="full_name" data-raw-value="<?php echo metis_escape_attr( $full_name ); ?>" style="margin-bottom: 8px;" title="Double-click to edit name"><?php echo metis_escape_html( $full_name ); ?></h1>
            <div class="metis-muted">CID: <?php echo metis_escape_html( (string) ( $contact->cid ?? '—' ) ); ?></div>
        </div>
        <div class="metis-flex metis-top-actions">
            <?php if ( $is_donor && $donor_profile_url !== '' ) : ?>
                <a href="<?php echo metis_escape_url( $donor_profile_url ); ?>" class="metis-btn metis-top-action-btn">View Donor Profile</a>
            <?php endif; ?>
            <?php if ( metis_contacts_can_manage() ) : ?>
                <button type="button" id="metis-open-contact-edit" class="metis-btn metis-top-action-btn">Edit Contact</button>
                <button type="button" id="metis-open-note-modal" class="metis-btn metis-top-action-btn">Add Note</button>
            <?php endif; ?>
            <a href="<?php echo metis_escape_url( metis_portal_url( 'contacts' ) ); ?>" class="metis-btn metis-top-action-btn">Back to Contacts</a>
        </div>
    </div>

    <div class="metis-contact-detail-grid">
        <section class="metis-contact-card">
            <h3>Primary</h3>
            <div>
                <strong>Email:</strong>
                <span
                    id="metis-primary-email-value"
                    class="metis-inline-editable"
                    data-field="email"
                    data-raw-value="<?php echo metis_escape_attr( (string) $contact->email ); ?>"
                    data-email-options="<?php echo metis_escape_attr( metis_json_encode( $inline_email_options ) ); ?>"
                    title="Double-click to edit"
                ><?php echo metis_escape_html( (string) $contact->email ); ?></span>
            </div>
            <div>
                <strong>Phone:</strong>
                <span
                    id="metis-primary-phone-value"
                    class="metis-inline-editable"
                    data-field="phone"
                    data-raw-value="<?php echo metis_escape_attr( $phone_display ); ?>"
                    title="Double-click to edit"
                ><?php echo metis_escape_html( $phone_display !== '' ? $phone_display : '—' ); ?></span>
            </div>
            <div><strong>CID:</strong> <?php echo metis_escape_html( (string) ( $contact->cid ?? '—' ) ); ?></div>
            <div><strong>Donor ID:</strong> <?php echo metis_escape_html( (string) ( $contact->did ?: '—' ) ); ?></div>
        </section>

        <?php if ( $has_extra_details ) : ?>
        <section class="metis-contact-card">
            <h3>Contact Details</h3>
            <div><strong>Preferred Name:</strong> <span class="metis-inline-editable" data-field="preferred_name" data-raw-value="<?php echo metis_escape_attr( $preferred_name_display ); ?>" title="Double-click to edit"><?php echo metis_escape_html( $preferred_name_display !== '' ? $preferred_name_display : '—' ); ?></span></div>
            <div><strong>Preferred Contact Method:</strong> <span class="metis-inline-editable" data-field="preferred_contact_method" data-raw-value="<?php echo metis_escape_attr( $preferred_contact_method_display ); ?>" title="Double-click to edit"><?php echo metis_escape_html( $preferred_contact_method_display !== '' ? $preferred_contact_method_display : '—' ); ?></span></div>
            <div>
                <strong>Address:</strong>
                <span
                    class="metis-inline-editable metis-address-block"
                    data-field="address_full"
                    data-raw-value="<?php echo metis_escape_attr( trim( implode( ', ', array_filter( [ $address_line_1, $address_line_2 ] ) ) ) ); ?>"
                    title="Double-click to edit"
                >
                    <?php if ( $address_line_1 !== '' || $address_line_2 !== '' ) : ?>
                        <?php if ( $address_line_1 !== '' ) : ?><span class="metis-address-line"><?php echo metis_escape_html( $address_line_1 ); ?></span><?php endif; ?>
                        <?php if ( $address_line_2 !== '' ) : ?><span class="metis-address-line"><?php echo metis_escape_html( $address_line_2 ); ?></span><?php endif; ?>
                    <?php else : ?>
                        <span class="metis-address-line">—</span>
                    <?php endif; ?>
                </span>
            </div>
            <div><strong>Birthday:</strong> <span class="metis-inline-editable" data-field="birthday" data-raw-value="<?php echo metis_escape_attr( $birthday_display ); ?>" title="Double-click to edit"><?php echo metis_escape_html( $birthday_display !== '' ? $birthday_display : '—' ); ?></span></div>
            <div><strong>Household ID:</strong> <span class="metis-inline-editable" data-field="household_id" data-raw-value="<?php echo metis_escape_attr( $household_id_display ); ?>" title="Double-click to edit"><?php echo metis_escape_html( $household_id_display !== '' ? $household_id_display : '—' ); ?></span></div>
            <div><strong>First Contacted:</strong> <?php echo metis_escape_html( $first_contacted_display !== '' ? metis_contacts_format_datetime( $first_contacted_display, 'm/d/y g:ia' ) : '—' ); ?></div>
            <div><strong>Do Not Contact:</strong> <span class="metis-inline-editable" data-field="do_not_contact" data-input-type="checkbox" data-raw-value="<?php echo metis_escape_attr( ! empty( $details->do_not_contact ) ? '1' : '0' ); ?>" title="Double-click to edit"><?php echo metis_escape_html( $do_not_contact_display ); ?></span></div>
            <div><strong>Volunteer:</strong> <span class="metis-inline-editable" data-field="volunteer_status" data-input-type="checkbox" data-raw-value="<?php echo metis_escape_attr( ! empty( $details->volunteer_status ) ? '1' : '0' ); ?>" title="Double-click to edit"><?php echo metis_escape_html( $volunteer_status_display ); ?></span></div>
            <div><strong>Anonymous Donor:</strong> <span class="metis-inline-editable" data-field="anonymous_donor" data-input-type="checkbox" data-raw-value="<?php echo metis_escape_attr( ! empty( $details->anonymous_donor ) ? '1' : '0' ); ?>" title="Double-click to edit"><?php echo metis_escape_html( $anonymous_donor_display ); ?></span></div>
        </section>
        <?php endif; ?>

        <?php if ( metis_contacts_can_manage() || ! empty( $additional_emails ) ) : ?>
        <section id="metis-additional-emails-section" class="metis-contact-card">
            <div class="metis-card-header">
                <h3>Additional Emails</h3>
                <?php if ( metis_contacts_can_manage() ) : ?>
                    <button type="button" id="metis-open-additional-email-modal" class="metis-btn-xs">+</button>
                <?php endif; ?>
            </div>
            <div id="metis-additional-emails-view">
                <?php if ( ! empty( $additional_emails ) ) : ?>
                <div class="metis-chip-list">
                    <?php foreach ( $additional_emails as $entry ) : ?>
                        <span class="metis-chip">
                            <span><?php echo metis_escape_html( (string) $entry ); ?></span>
                            <?php if ( metis_contacts_can_manage() ) : ?>
                                <button
                                    type="button"
                                    class="metis-chip-remove"
                                    data-email="<?php echo metis_escape_attr( (string) $entry ); ?>"
                                    aria-label="<?php echo metis_escape_attr( 'Remove ' . (string) $entry ); ?>"
                                >×</button>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php else : ?>
                    <div class="metis-muted">No additional emails recorded.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( metis_contacts_can_manage() || ! empty( $relationships ) ) : ?>
        <section id="metis-relationships-section" class="metis-contact-card">
            <div class="metis-card-header">
                <h3>Relationships</h3>
                <?php if ( metis_contacts_can_manage() ) : ?>
                    <button type="button" id="metis-open-relationships-plus" class="metis-btn-xs">+</button>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $relationships ) ) : ?>
            <div class="metis-chip-list">
                    <?php foreach ( $relationships as $entry ) :
                        $type = is_array( $entry ) ? ( $entry['relation_type'] ?? '' ) : '';
                        $notes_text = is_array( $entry ) ? ( $entry['notes'] ?? '' ) : '';
                        $related_cid = is_array( $entry ) ? (string) ( $entry['related_contact_cid'] ?? $entry['related_contact_id'] ?? '' ) : '';
                        $name = 'Related Contact';
                        if ( $related_cid !== '' && isset( $contact_name_by_cid[ $related_cid ] ) ) {
                            $name = (string) $contact_name_by_cid[ $related_cid ];
                        } elseif ( is_array( $entry ) && ! empty( $entry['name'] ) ) {
                            $name = (string) $entry['name'];
                        } elseif ( ! is_array( $entry ) ) {
                            $name = (string) $entry;
                        }
                    ?>
                        <?php if ( $related_cid !== '' ) : ?>
                            <span class="metis-chip metis-relationship-chip">
                                <a class="metis-chip-link" href="<?php echo metis_escape_url( metis_contacts_detail_url( $related_cid ) ); ?>">
                                    <?php echo metis_escape_html( $name !== '' ? $name : 'Related Contact' ); ?>
                                    <?php if ( $type !== '' ) : ?><span class="metis-muted">(<?php echo metis_escape_html( $type ); ?>)</span><?php endif; ?>
                                    <?php if ( $notes_text !== '' ) : ?><span class="metis-muted"> - <?php echo metis_escape_html( $notes_text ); ?></span><?php endif; ?>
                                </a>
                                <?php if ( metis_contacts_can_manage() ) : ?>
                                    <button
                                        type="button"
                                        class="metis-chip-remove metis-remove-relationship-chip"
                                        data-related-cid="<?php echo metis_escape_attr( $related_cid ); ?>"
                                        data-relation-type="<?php echo metis_escape_attr( (string) $type ); ?>"
                                        data-notes="<?php echo metis_escape_attr( (string) $notes_text ); ?>"
                                        aria-label="Remove relationship"
                                    >×</button>
                                <?php endif; ?>
                            </span>
                        <?php else : ?>
                            <span class="metis-chip">
                                <?php echo metis_escape_html( $name !== '' ? $name : 'Related Contact' ); ?>
                                <?php if ( $type !== '' ) : ?><span class="metis-muted">(<?php echo metis_escape_html( $type ); ?>)</span><?php endif; ?>
                                <?php if ( $notes_text !== '' ) : ?><span class="metis-muted"> - <?php echo metis_escape_html( $notes_text ); ?></span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
            </div>
            <?php else : ?>
                <div class="metis-muted">No relationships recorded.</div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ( metis_contacts_can_manage() || ! empty( $newsletter_selected_names ) ) : ?>
        <section id="metis-newsletter-section" class="metis-contact-card">
            <div class="metis-card-header">
                <h3>Newsletter Subscriptions</h3>
                <?php if ( metis_contacts_can_manage() ) : ?>
                    <button type="button" id="metis-open-newsletter-modal" class="metis-btn-xs">+</button>
                <?php endif; ?>
            </div>
            <div id="metis-newsletter-view">
                <?php if ( ! empty( $newsletter_selected_names ) ) : ?>
                <div class="metis-chip-list">
                    <?php foreach ( $newsletter_selected_names as $entry ) : ?>
                        <span class="metis-chip">
                            <span><?php echo metis_escape_html( (string) $entry['name'] ); ?></span>
                            <?php if ( metis_contacts_can_manage() ) : ?>
                                <button type="button" class="metis-chip-remove metis-remove-newsletter-chip" data-list-id="<?php echo metis_escape_attr( (string) $entry['id'] ); ?>" aria-label="Remove newsletter list">×</button>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php else : ?>
                    <div class="metis-muted">No newsletter subscriptions.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( $is_donor ) : ?>
        <section class="metis-contact-card">
            <h3>Donor Details</h3>
            <div><strong>Total Contributions:</strong> <?php echo metis_escape_html( '$' . number_format( $total_contributions, 2 ) ); ?></div>
            <?php
                $last_amount = ( $last_donation && isset( $last_donation->amount ) && is_numeric( $last_donation->amount ) )
                    ? '$' . number_format( (float) $last_donation->amount, 2 )
                    : '$0.00';
                $last_date = ( $last_donation && ! empty( $last_donation->tran_date ) )
                    ? metis_contacts_format_datetime( (string) $last_donation->tran_date, 'm/d/y' )
                    : '—';
                $last_campaign = '—';
                if ( $last_donation ) {
                    $last_campaign = (string) ( $last_donation->campaign_name ?? '' );
                    if ( $last_campaign === '' ) {
                        $last_campaign = (string) ( $last_donation->campaign_code ?? '' );
                    }
                    if ( $last_campaign === '' ) {
                        $last_campaign = '—';
                    }
                }
            ?>
            <div class="metis-muted" style="font-size: 13px; margin-top: 4px;">Last donation made</div>
            <div><strong>Amount:</strong> <?php echo metis_escape_html( $last_amount ); ?></div>
            <div><strong>Campaign:</strong> <?php echo metis_escape_html( $last_campaign ); ?></div>
            <div><strong>Date:</strong> <?php echo metis_escape_html( $last_date ); ?></div>
        </section>
        <?php endif; ?>

        <section class="metis-contact-card" style="grid-column: 1 / -1;">
            <div class="metis-card-header">
                <h3>Recent Notes</h3>
                <?php if ( metis_contacts_can_manage() ) : ?>
                    <button type="button" id="metis-open-note-modal-plus" class="metis-btn-xs">+</button>
                <?php endif; ?>
            </div>
            <div id="metis-notes-list" class="metis-contact-notes-list">
                <?php if ( ! empty( $notes ) ) : ?>
                    <?php foreach ( $notes as $n ) :
                        $author = ! empty( $n->author_name ) ? (string) $n->author_name : 'System';
                        $when = metis_contacts_format_datetime( (string) ( $n->created_at ?? '' ), 'm/d/y g:ia' );
                    ?>
                        <article class="metis-contact-note-item">
                            <div><?php echo metis_escape_html( (string) $n->note ); ?></div>
                            <div class="metis-muted" style="font-size: 12px; margin-top: 4px;">
                                <?php echo metis_escape_html( $author . ' - ' . $when ); ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-muted" id="metis-no-notes">No notes found.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php if ( metis_contacts_can_manage() ) : ?>
    <div id="metis-contact-detail-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal">
            <h3 class="metis-modal-title">Edit Contact</h3>
            <form id="metis-contact-detail-form" data-contact-cid="<?php echo metis_escape_attr( (string) $contact->cid ); ?>" class="metis-form-grid">
                <div class="metis-contact-tabs metis-field-full">
                    <button type="button" class="metis-btn-xs metis-contact-tab is-active" data-tab="basic">Basic</button>
                    <button type="button" class="metis-btn-xs metis-contact-tab" data-tab="details">Details</button>
                    <button type="button" class="metis-btn-xs metis-contact-tab" data-tab="flags">Flags</button>
                    <button type="button" class="metis-btn-xs metis-contact-tab" data-tab="newsletter">Newsletter</button>
                </div>
                <div class="metis-contact-tab-panel is-active" data-tab-panel="basic">
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-first-name">First Name</label>
                        <input id="metis-detail-first-name" class="metis-input" type="text" required value="<?php echo metis_escape_attr( (string) $contact->first_name ); ?>">
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-last-name">Last Name</label>
                        <input id="metis-detail-last-name" class="metis-input" type="text" required value="<?php echo metis_escape_attr( (string) $contact->last_name ); ?>">
                    </div>
                    <div class="metis-field metis-field-full">
                        <label for="metis-detail-email">Email</label>
                        <select id="metis-detail-email" class="metis-select" required>
                            <?php
                            $email_options = array_values( array_unique( array_filter( array_map(
                                static function ( $email_value ) {
                                    $candidate = (string) $email_value;
                                    if ( class_exists( 'Normalizer' ) ) {
                                        $normalized = Normalizer::normalize( $candidate, Normalizer::FORM_KC );
                                        if ( is_string( $normalized ) ) {
                                            $candidate = $normalized;
                                        }
                                    }
                                    $candidate = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\p{Cf}]/u', '', $candidate );
                                    $candidate = preg_replace( '/\s+/u', '', $candidate );
                                    return strtolower( trim( $candidate ) );
                                },
                                array_merge( [ (string) $contact->email ], $additional_emails )
                            ) ) ) );
                            $primary_email_value = (string) $contact->email;
                            if ( class_exists( 'Normalizer' ) ) {
                                $normalized_primary = Normalizer::normalize( $primary_email_value, Normalizer::FORM_KC );
                                if ( is_string( $normalized_primary ) ) {
                                    $primary_email_value = $normalized_primary;
                                }
                            }
                            $primary_email_value = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\p{Cf}]/u', '', $primary_email_value );
                            $primary_email_value = preg_replace( '/\s+/u', '', $primary_email_value );
                            $primary_email_value = strtolower( trim( $primary_email_value ) );
                            foreach ( $email_options as $email_option ) :
                            ?>
                                <option value="<?php echo metis_escape_attr( $email_option ); ?>" <?php metis_attr_selected( $email_option === $primary_email_value ); ?>><?php echo metis_escape_html( $email_option ); ?></option>
                            <?php endforeach; ?>
                            <option value="__new__">Type new email...</option>
                        </select>
                        <div class="metis-muted" style="font-size:12px; margin-top:4px;">Choose an existing email or select "Type new email...".</div>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-phone">Phone</label>
                        <input id="metis-detail-phone" class="metis-input" type="text" value="<?php echo metis_escape_attr( $phone_display ); ?>" placeholder="xxx-xxx-xxxx">
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-preferred-name">Preferred Name</label>
                        <input id="metis-detail-preferred-name" class="metis-input" type="text" value="<?php echo metis_escape_attr( $preferred_name_display ); ?>">
                    </div>
                    <div class="metis-field metis-field-full">
                        <label for="metis-detail-preferred-contact-method">Preferred Contact Method</label>
                        <input id="metis-detail-preferred-contact-method" class="metis-input" type="text" value="<?php echo metis_escape_attr( $preferred_contact_method_display ); ?>">
                    </div>
                </div>
                <div class="metis-contact-tab-panel" data-tab-panel="details">
                    <div class="metis-field metis-field-full">
                        <label for="metis-detail-address">Address</label>
                        <input id="metis-detail-address" class="metis-input" type="text" value="<?php echo metis_escape_attr( $address_display ); ?>">
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-city">City</label>
                        <input id="metis-detail-city" class="metis-input" type="text" value="<?php echo metis_escape_attr( $city_display ); ?>">
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-state">State</label>
                        <select id="metis-detail-state" class="metis-select">
                            <option value="">Select state</option>
                            <?php foreach ( $us_states as $st ) : ?>
                                <option value="<?php echo metis_escape_attr( $st ); ?>" <?php metis_attr_selected( strtoupper( trim( (string) $state_display ) ) === $st ); ?>><?php echo metis_escape_html( $st ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-zip">ZIP</label>
                        <input id="metis-detail-zip" class="metis-input" type="text" value="<?php echo metis_escape_attr( $zip_display ); ?>">
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-birthday">Birthday</label>
                        <input id="metis-detail-birthday" class="metis-input" type="date" value="<?php echo metis_escape_attr( $birthday_display ); ?>">
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-detail-household-id">Household ID</label>
                        <input id="metis-detail-household-id" class="metis-input" type="text" value="<?php echo metis_escape_attr( $household_id_display ); ?>">
                    </div>
                    <div class="metis-field metis-field-full">
                        <label>Additional Emails</label>
                        <div id="metis-additional-emails-rows" class="metis-editor-list"></div>
                        <button type="button" id="metis-add-email-row" class="metis-btn-xs">Add Email</button>
                        <input type="hidden" id="metis-detail-additional-emails-json" value="<?php echo metis_escape_attr( metis_json_encode( $additional_emails ) ); ?>">
                    </div>
                    <div class="metis-field metis-field-full">
                        <label>Relationships</label>
                        <div id="metis-relationships-list" class="metis-editor-list"></div>
                        <button type="button" id="metis-add-relationship" class="metis-btn-xs">Manage Relationships</button>
                        <input type="hidden" id="metis-detail-relationships-json" value="<?php echo metis_escape_attr( metis_json_encode( $relationships ) ); ?>">
                    </div>
                </div>
                <div class="metis-contact-tab-panel" data-tab-panel="flags">
                    <div class="metis-field metis-field-full">
                        <label><input id="metis-detail-do-not-contact" type="checkbox" <?php metis_attr_checked( ! empty( $details->do_not_contact ) ); ?>> Do Not Contact</label>
                    </div>
                    <div class="metis-field metis-field-full">
                        <label><input id="metis-detail-volunteer-status" type="checkbox" <?php metis_attr_checked( ! empty( $details->volunteer_status ) ); ?>> Volunteer</label>
                    </div>
                    <div class="metis-field metis-field-full">
                        <label><input id="metis-detail-anonymous-donor" type="checkbox" <?php metis_attr_checked( ! empty( $details->anonymous_donor ) ); ?>> Anonymous Donor</label>
                    </div>
                </div>
                <div class="metis-contact-tab-panel" data-tab-panel="newsletter">
                    <div class="metis-field metis-field-full">
                        <label>Newsletter Lists</label>
                        <div class="metis-newsletter-list">
                            <?php foreach ( $newsletter_lists as $list_row ) : ?>
                                <?php $list_id = (int) ( $list_row->id ?? 0 ); ?>
                                <label class="metis-newsletter-list-item">
                                    <input
                                        type="checkbox"
                                        class="metis-newsletter-list-input"
                                        value="<?php echo metis_escape_attr( (string) $list_id ); ?>"
                                        <?php metis_attr_checked( in_array( $list_id, $newsletter_selected_ids, true ) ); ?>
                                    >
                                    <?php echo metis_escape_html( (string) ( $list_row->name ?? '' ) ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="metis-form-actions">
                    <button type="button" id="metis-contact-detail-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                    <button type="submit" class="metis-btn">Save Details</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-relationship-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal">
            <h3 class="metis-modal-title">Manage Relationships</h3>
            <div id="metis-relationship-rows" class="metis-editor-list"></div>
            <button type="button" id="metis-relationship-add-row" class="metis-btn-xs">Add Another Contact</button>
            <div class="metis-form-actions" style="margin-top:12px;">
                <button type="button" id="metis-relationship-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="button" id="metis-relationship-save" class="metis-btn">Save Relationships</button>
            </div>
        </div>
    </div>

    <div id="metis-note-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal">
            <h3 class="metis-modal-title">Add Note</h3>
            <div class="metis-field metis-field-full">
                <label for="metis-contact-note-text">Note</label>
                <textarea id="metis-contact-note-text" class="metis-field" rows="5" placeholder="Enter note text..."></textarea>
            </div>
            <div class="metis-form-actions" style="margin-top:12px;">
                <button type="button" id="metis-note-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="button" id="metis-note-save" class="metis-btn">Save Note</button>
            </div>
        </div>
    </div>

    <div id="metis-email-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal" style="max-width:520px;">
            <h3 class="metis-modal-title">Use New Primary Email</h3>
            <div class="metis-field metis-field-full">
                <label for="metis-email-modal-input">Email</label>
                <input id="metis-email-modal-input" class="metis-input" type="email" placeholder="name@example.org">
                <div id="metis-email-modal-error" class="metis-muted" style="display:none; color:#b91c1c; font-size:12px; margin-top:6px;">Please enter a valid email address.</div>
            </div>
            <div class="metis-form-actions" style="margin-top:12px;">
                <button type="button" id="metis-email-modal-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="button" id="metis-email-modal-save" class="metis-btn">Use Email</button>
            </div>
        </div>
    </div>

    <div id="metis-additional-email-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal" style="max-width:520px;">
            <h3 class="metis-modal-title">Add Additional Email</h3>
            <div class="metis-field metis-field-full">
                <label for="metis-additional-email-modal-input">Email</label>
                <input id="metis-additional-email-modal-input" class="metis-input" type="email" placeholder="name@example.org">
            </div>
            <div class="metis-form-actions" style="margin-top:12px;">
                <button type="button" id="metis-additional-email-modal-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="button" id="metis-additional-email-modal-save" class="metis-btn">Add Email</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal" style="max-width:520px;">
            <h3 class="metis-modal-title">Add Newsletter Subscription</h3>
            <div class="metis-field metis-field-full">
                <label for="metis-newsletter-modal-select">Newsletter List</label>
                <select id="metis-newsletter-modal-select" class="metis-select">
                    <option value="">Select a list</option>
                    <?php foreach ( $newsletter_lists as $list_row ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( (int) ( $list_row->id ?? 0 ) ) ); ?>"><?php echo metis_escape_html( (string) ( $list_row->name ?? '' ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="metis-form-actions" style="margin-top:12px;">
                <button type="button" id="metis-newsletter-modal-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="button" id="metis-newsletter-modal-save" class="metis-btn">Add</button>
            </div>
        </div>
    </div>

    <select id="metis-relationship-contacts-template" style="display:none;">
        <option value="">Select a contact</option>
        <?php foreach ( $all_contacts as $c ) :
            $name = trim( (string) $c->first_name . ' ' . (string) $c->last_name );
            $name = $name !== '' ? $name : (string) $c->email;
        ?>
            <option value="<?php echo metis_escape_attr( (string) $c->cid ); ?>" data-name="<?php echo metis_escape_attr( $name ); ?>"><?php echo metis_escape_html( $name . ' (' . $c->email . ')' ); ?></option>
        <?php endforeach; ?>
    </select>
    <select id="metis-relationship-types-template" style="display:none;">
        <option value="">Select type</option>
        <?php foreach ( $relation_types as $type ) : ?>
            <option value="<?php echo metis_escape_attr( $type ); ?>"><?php echo metis_escape_html( $type ); ?></option>
        <?php endforeach; ?>
    </select>
<?php endif; ?>

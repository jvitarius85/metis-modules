<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

use Metis\Http\Response;

final class FormRenderer {
    public static function render( array $form, array $result = [] ): Response {
        $title = (string) ( $form['name'] ?? 'Form' );
        $asset_css = \metis_module_asset_url( 'forms', 'forms.css' );
        $asset_js = \metis_module_asset_url( 'forms', 'forms.js' );
        $instance_id = 'metis-forms-public-' . (string) ( (int) ( $form['id'] ?? 0 ) ) . '-page';

        ob_start();
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo metis_escape_html( $title ); ?></title>
    <link rel="stylesheet" href="<?php echo metis_escape_url( $asset_css ); ?>">
    <?php if ( self::paymentField( (array) ( $form['schema'] ?? [] ) ) !== null ) : ?>
        <script src="https://js.stripe.com/v3/"></script>
    <?php endif; ?>
</head>
<body class="metis-forms-public-body">
    <main class="metis-forms-public-shell">
        <?php echo self::renderMarkup( $form, $result, [ 'embedded' => false, 'instance_id' => $instance_id ] ); ?>
    </main>
    <script src="<?php echo metis_escape_url( $asset_js ); ?>"></script>
</body>
</html>
        <?php

        return Response::html( (string) ob_get_clean(), (int) ( $result['status'] ?? 200 ) );
    }

    public static function renderEmbedHtml( array $form, array $options = [] ): string {
        $instance_id = 'metis-forms-public-' . (string) ( (int) ( $form['id'] ?? 0 ) ) . '-embed';
        $asset_css = \metis_module_asset_url( 'forms', 'forms.css' );
        $asset_js = \metis_module_asset_url( 'forms', 'forms.js' );
        $needs_stripe = self::paymentField( (array) ( $form['schema'] ?? [] ) ) !== null;
        $loader = '(function(){'
            . 'if(!document.getElementById("metis-forms-public-css")){'
            . 'var link=document.createElement("link");'
            . 'link.id="metis-forms-public-css";'
            . 'link.rel="stylesheet";'
            . 'link.href=' . \metis_json_encode( (string) $asset_css ) . ';'
            . 'document.head.appendChild(link);'
            . '}'
            . ( $needs_stripe
                ? 'if(!window.Stripe&&!document.getElementById("metis-forms-stripe-js")){'
                    . 'var stripe=document.createElement("script");'
                    . 'stripe.id="metis-forms-stripe-js";'
                    . 'stripe.src="https://js.stripe.com/v3/";'
                    . 'document.head.appendChild(stripe);'
                . '}'
                : '' )
            . 'if(!window.__metisFormsPublicScriptRequested){'
            . 'window.__metisFormsPublicScriptRequested=true;'
            . 'var script=document.createElement("script");'
            . 'script.src=' . \metis_json_encode( (string) $asset_js ) . ';'
            . 'script.onload=function(){'
                . 'if(window.Metis&&Metis.forms&&typeof Metis.forms.initPublicEmbeds==="function"){'
                    . 'Metis.forms.initPublicEmbeds(document);'
                . '}'
            . '};'
            . 'document.body.appendChild(script);'
            . '}else if(window.Metis&&Metis.forms&&typeof Metis.forms.initPublicEmbeds==="function"){'
                . 'Metis.forms.initPublicEmbeds(document);'
            . '}'
        . '})();';

        return self::renderMarkup( $form, [], [ 'embedded' => true, 'instance_id' => $instance_id ] )
            . '<script>' . $loader . '</script>';
    }

    private static function renderMarkup( array $form, array $result = [], array $options = [] ): string {
        $schema = (array) ( $form['schema'] ?? [] );
        $settings = (array) ( $form['settings'] ?? [] );
        $title = (string) ( $form['name'] ?? 'Form' );
        $description = (string) ( $form['description'] ?? '' );
        $blocked = ! empty( $result['blocked'] );
        $alert_class = ! empty( $result['ok'] ) ? 'metis-alert-success' : 'metis-alert-error';
        $payment_field = self::paymentField( $schema );
        $embedded = ! empty( $options['embedded'] );
        $instance_id = (string) ( $options['instance_id'] ?? ( 'metis-forms-public-' . (string) ( (int) ( $form['id'] ?? 0 ) ) ) );

        $boot = [
            'mode'   => 'public',
            'form'   => [
                'id'          => (int) ( $form['id'] ?? 0 ),
                'slug'        => (string) ( $form['slug'] ?? '' ),
                'name'        => $title,
                'schema'      => $schema,
                'settings'    => $settings,
                'submit_url'  => Support::publicUrl( (string) ( $form['slug'] ?? '' ) ),
                'has_payment' => $payment_field !== null,
            ],
            'result' => $result,
        ];

        ob_start();
        ?>
        <section class="metis-forms-public-card<?php echo $embedded ? ' metis-forms-public-card--embed' : ''; ?>" data-metis-forms-public="1" data-metis-forms-instance="<?php echo metis_escape_attr( $instance_id ); ?>">
            <?php if ( ! $embedded ) : ?>
            <header class="metis-forms-public-header">
                <p class="metis-forms-public-kicker">Metis Forms</p>
                <h1><?php echo metis_escape_html( $title ); ?></h1>
                <?php if ( $description !== '' ) : ?>
                    <p><?php echo metis_escape_html( $description ); ?></p>
                <?php endif; ?>
            </header>
            <?php endif; ?>

            <div id="<?php echo metis_escape_attr( $instance_id . '-alert' ); ?>" class="metis-alert <?php echo metis_escape_attr( $alert_class ); ?>" data-metis-forms-alert<?php echo empty( $result['message'] ) ? ' hidden' : ''; ?>>
                <?php echo ! empty( $result['message'] ) ? \metis_runtime_kses_post( (string) $result['message'] ) : ''; ?>
            </div>

            <div id="<?php echo metis_escape_attr( $instance_id . '-success-overlay' ); ?>" class="metis-forms-success-overlay" data-metis-forms-success-overlay hidden>
                <div class="metis-forms-success-overlay__backdrop" data-success-close></div>
                <div class="metis-forms-success-overlay__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo metis_escape_attr( $instance_id . '-success-title' ); ?>">
                    <h2 id="<?php echo metis_escape_attr( $instance_id . '-success-title' ); ?>">Submission received</h2>
                    <p data-success-message>Thanks, your submission has been received.</p>
                    <button type="button" class="metis-btn" data-success-close>Close</button>
                </div>
            </div>

            <?php if ( ! $blocked ) : ?>
                <form id="<?php echo metis_escape_attr( $instance_id . '-form' ); ?>" class="metis-forms-public-form" data-metis-forms-public-form action="<?php echo metis_escape_url( Support::publicUrl( (string) ( $form['slug'] ?? '' ) ) ); ?>" method="post" novalidate>
                    <?php foreach ( $schema as $field ) : ?>
                        <?php if ( is_array( $field ) ) : ?>
                            <?php self::renderField( $field, '', $instance_id ); ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ( ( $settings['access']['mode'] ?? '' ) === 'password' ) : ?>
                        <section class="metis-forms-public-field is-full">
                            <label for="<?php echo metis_escape_attr( $instance_id . '-access-password' ); ?>">Form password</label>
                            <input id="<?php echo metis_escape_attr( $instance_id . '-access-password' ); ?>" class="metis-input" type="password" name="_access_password" autocomplete="current-password">
                        </section>
                    <?php endif; ?>

                    <div class="metis-forms-public-actions">
                        <button id="<?php echo metis_escape_attr( $instance_id . '-submit-button' ); ?>" class="metis-btn" data-metis-forms-submit-button type="submit">
                            <?php echo $payment_field !== null ? 'Continue to payment' : 'Submit'; ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
            <script type="application/json" data-metis-forms-public-data><?php echo \metis_json_encode( $boot ); ?></script>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderField( array $field, string $name_prefix = '', string $dom_prefix = '' ): void {
        $type = (string) ( $field['type'] ?? 'text' );
        if ( $type === 'payment' ) {
            self::renderPaymentField( $field, $dom_prefix );
            return;
        }

        $key = (string) ( $field['key'] ?? '' );
        if ( $key === '' ) {
            return;
        }

        $label = (string) ( $field['label'] ?? $key );
        $help = (string) ( $field['help'] ?? '' );
        $required = ! empty( $field['required'] );
        $width = (string) ( $field['width'] ?? 'full' );
        if ( ! in_array( $width, [ 'full', 'half', 'narrow' ], true ) ) {
            $width = 'full';
        }
        $name = $name_prefix !== '' ? $name_prefix . '[' . $key . ']' : $key;
        $id = $dom_prefix !== '' ? $dom_prefix . '__' . $key : $key;
        $help_id = $help !== '' ? 'metis-help-' . $id : '';
        $attrs = [
            'data-field-key' => $key,
            'data-field-type' => $type,
        ];
        if ( ! empty( $field['conditions'] ) ) {
            $attrs['data-conditions'] = \metis_json_encode( $field['conditions'] ) ?: '[]';
        }
        if ( ! empty( $field['options_source']['type'] ) ) {
            $attrs['data-source-type'] = (string) $field['options_source']['type'];
        }
        if ( ! empty( $field['options_source']['parent_field'] ) ) {
            $attrs['data-parent-field'] = (string) $field['options_source']['parent_field'];
        }

        echo '<section class="metis-forms-public-field is-' . metis_escape_attr( $width ) . '"';
        foreach ( $attrs as $attr => $value ) {
            echo ' ' . metis_escape_attr( $attr ) . '="' . metis_escape_attr( (string) $value ) . '"';
        }
        echo '>';

        if ( $type !== 'radio' && $type !== 'checkbox' ) {
            echo '<label for="' . metis_escape_attr( 'field-' . $id ) . '">' . metis_escape_html( $label );
            if ( $required ) {
                echo ' <span aria-hidden="true">*</span>';
            }
            echo '</label>';
            if ( $help !== '' ) {
                echo '<p id="' . metis_escape_attr( $help_id ) . '" class="metis-forms-help">' . metis_escape_html( $help ) . '</p>';
            }
        }

        $described = $help_id !== '' ? ' aria-describedby="' . metis_escape_attr( $help_id ) . '"' : '';

        if ( $type === 'textarea' ) {
            echo '<textarea id="' . metis_escape_attr( 'field-' . $id ) . '" class="metis-input" name="' . metis_escape_attr( $name ) . '" placeholder="' . metis_escape_attr( (string) ( $field['placeholder'] ?? '' ) ) . '"' . ( $required ? ' required' : '' ) . $described . '></textarea>';
        } elseif ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
            $options = (array) ( $field['options'] ?? [] );
            if ( $type === 'select' ) {
                $is_child_dynamic = ! empty( $field['options_source']['parent_field'] );
                echo '<select id="' . metis_escape_attr( 'field-' . $id ) . '" class="metis-select" name="' . metis_escape_attr( $name ) . '"' . ( $required ? ' required' : '' ) . $described . ( $is_child_dynamic ? ' disabled' : '' ) . '>';
                echo '<option value="">Select…</option>';
                foreach ( $options as $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    echo '<option value="' . metis_escape_attr( (string) ( $option['value'] ?? '' ) ) . '" data-category="' . metis_escape_attr( (string) ( $option['category'] ?? '' ) ) . '">'
                        . metis_escape_html( (string) ( $option['label'] ?? '' ) ) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<fieldset class="metis-forms-choice-group"' . $described . '>';
                echo '<legend>' . metis_escape_html( $label );
                if ( $required ) {
                    echo ' <span aria-hidden="true">*</span>';
                }
                echo '</legend>';
                if ( $help !== '' ) {
                    echo '<p id="' . metis_escape_attr( $help_id ) . '" class="metis-forms-help">' . metis_escape_html( $help ) . '</p>';
                }
                foreach ( $options as $index => $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    $choice_id = 'field-' . $id . '-' . (int) $index;
                    $choice_name = $type === 'checkbox' ? $name . '[]' : $name;
                    echo '<label class="metis-forms-choice" for="' . metis_escape_attr( $choice_id ) . '">';
                    echo '<input type="' . metis_escape_attr( $type === 'checkbox' ? 'checkbox' : 'radio' ) . '" id="' . metis_escape_attr( $choice_id ) . '" name="' . metis_escape_attr( $choice_name ) . '" value="' . metis_escape_attr( (string) ( $option['value'] ?? '' ) ) . '"' . ( $required && $type === 'radio' && (int) $index === 0 ? ' required' : '' ) . '>';
                    echo '<span>' . metis_escape_html( (string) ( $option['label'] ?? '' ) ) . '</span>';
                    echo '</label>';
                }
                echo '</fieldset>';
            }
        } elseif ( $type === 'repeater' ) {
            $limit = max( 1, (int) ( $field['repeat_limit'] ?? 5 ) );
            echo '<div class="metis-forms-repeater" data-repeater-key="' . metis_escape_attr( $key ) . '" data-repeat-limit="' . metis_escape_attr( (string) $limit ) . '">';
            echo '<div class="metis-forms-repeater-rows"></div>';
            echo '<template>';
            echo '<article class="metis-forms-repeater-row">';
            echo '<div class="metis-forms-repeater-grid">';
            foreach ( (array) ( $field['subfields'] ?? [] ) as $subfield ) {
                if ( is_array( $subfield ) ) {
                    self::renderField( $subfield, $name . '[__INDEX__]', $id . '__INDEX__' );
                }
            }
            echo '</div>';
            echo '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-remove-row>Remove</button>';
            echo '</article>';
            echo '</template>';
            echo '<button type="button" class="metis-btn metis-btn-xs" data-add-row>Add row</button>';
            echo '</div>';
        } else {
            $input_type = in_array( $type, [ 'email', 'number', 'date' ], true ) ? $type : 'text';
            echo '<input id="' . metis_escape_attr( 'field-' . $id ) . '" class="metis-input" type="' . metis_escape_attr( $input_type ) . '" name="' . metis_escape_attr( $name ) . '" placeholder="' . metis_escape_attr( (string) ( $field['placeholder'] ?? '' ) ) . '"' . ( $required ? ' required' : '' ) . $described;
            if ( $input_type === 'number' ) {
                if ( ( $field['validation']['min_value'] ?? null ) !== null ) {
                    echo ' min="' . metis_escape_attr( (string) $field['validation']['min_value'] ) . '"';
                }
                if ( ( $field['validation']['max_value'] ?? null ) !== null ) {
                    echo ' max="' . metis_escape_attr( (string) $field['validation']['max_value'] ) . '"';
                }
                echo ' step="0.01"';
            }
            echo '>';
        }

        echo '</section>';
    }

    private static function renderPaymentField( array $field, string $dom_prefix = '' ): void {
        $payment = (array) ( $field['payment'] ?? [] );
        $currency = strtoupper( (string) ( $payment['currency'] ?? 'USD' ) );
        $amounts = (array) ( $payment['donation_amounts'] ?? [] );
        $label = (string) ( $field['label'] ?? 'Payment' );
        $prefix = $dom_prefix !== '' ? $dom_prefix . '__' : '';

        echo '<section class="metis-forms-public-field is-full metis-forms-payment-field" data-field-key="' . metis_escape_attr( (string) ( $field['key'] ?? 'payment' ) ) . '" data-field-type="payment">';
        echo '<div class="metis-forms-payment-head"><h2>' . metis_escape_html( $label ) . '</h2></div>';
        echo '<fieldset class="metis-forms-payment-choices"><legend>Donation amount</legend><div class="metis-forms-payment-choice-grid">';
        foreach ( $amounts as $index => $amount ) {
            $choice_id = $prefix . 'payment-choice-' . (int) $index;
            $display = number_format( (float) $amount, 2 );
            echo '<label class="metis-forms-payment-choice" for="' . metis_escape_attr( $choice_id ) . '">';
            echo '<input type="radio" id="' . metis_escape_attr( $choice_id ) . '" name="_donation_amount_choice" value="' . metis_escape_attr( (string) $amount ) . '">';
            echo '<span>' . metis_escape_html( $currency . ' ' . $display ) . '</span>';
            echo '</label>';
        }
        echo '</div></fieldset>';
        echo '<input type="hidden" name="_donation_amount" value="">';

        echo '<fieldset class="metis-forms-payment-choices metis-forms-frequency-choices"><legend>Donation frequency</legend><div class="metis-forms-payment-choice-grid">';
        $frequencies = [
            'one_time' => 'One time',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'semiannual' => 'Semi annual',
            'annual' => 'Annual',
        ];
        foreach ( $frequencies as $frequency_key => $frequency_label ) {
            $choice_id = $prefix . 'payment-frequency-' . $frequency_key;
            echo '<label class="metis-forms-payment-choice" for="' . metis_escape_attr( $choice_id ) . '">';
            echo '<input type="radio" id="' . metis_escape_attr( $choice_id ) . '" name="_donation_frequency" value="' . metis_escape_attr( $frequency_key ) . '"' . ( $frequency_key === 'one_time' ? ' checked' : '' ) . '>';
            echo '<span>' . metis_escape_html( $frequency_label ) . '</span>';
            echo '</label>';
        }
        echo '</div></fieldset>';

        if ( ! empty( $payment['allow_custom_amount'] ) ) {
            $custom_amount_id = $prefix . 'metis-forms-custom-amount';
            echo '<div class="metis-forms-payment-custom">';
            echo '<label for="' . metis_escape_attr( $custom_amount_id ) . '">' . metis_escape_html( (string) ( $payment['custom_amount_label'] ?? 'Other amount' ) ) . '</label>';
            echo '<input id="' . metis_escape_attr( $custom_amount_id ) . '" class="metis-input" type="number" min="1" step="0.01" name="_donation_amount_custom" inputmode="decimal">';
            echo '</div>';
        }

        if ( ! empty( $payment['cover_fees_enabled'] ) ) {
            echo '<label class="metis-forms-payment-toggle">';
            echo '<input type="checkbox" name="_cover_fees" value="1">';
            echo '<span>' . metis_escape_html( (string) ( $payment['cover_fees_label'] ?? 'I would like to cover the processing fees.' ) ) . '</span>';
            echo '</label>';
        }

        echo '<div class="metis-forms-payment-totals" data-payment-totals>';
        echo '<div><span>Donation</span><strong data-total-base>' . metis_escape_html( $currency . ' 0.00' ) . '</strong></div>';
        echo '<div><span>Fees</span><strong data-total-fee>' . metis_escape_html( $currency . ' 0.00' ) . '</strong></div>';
        echo '<div class="is-grand"><span>' . metis_escape_html( (string) ( $payment['summary_label'] ?? 'Total' ) ) . '</span><strong data-total-grand>' . metis_escape_html( $currency . ' 0.00' ) . '</strong></div>';
        echo '</div>';
        echo '<div class="metis-forms-stripe-panel" hidden>';
        echo '<div id="' . metis_escape_attr( $prefix . 'metis-forms-stripe-mount' ) . '"></div>';
        echo '<button type="button" class="metis-btn" id="' . metis_escape_attr( $prefix . 'metis-forms-stripe-confirm' ) . '">Pay and submit</button>';
        echo '</div>';
        echo '</section>';
    }

    private static function paymentField( array $schema ): ?array {
        foreach ( $schema as $field ) {
            if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'payment' ) {
                return $field;
            }
        }

        return null;
    }
}

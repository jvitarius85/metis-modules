<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

use Metis\Http\Response;

final class FormRenderer {
    public static function render(array $form, array $result = []): Response {
        $schema = (array) ( $form['schema'] ?? [] );
        $settings = (array) ( $form['settings'] ?? [] );
        $design = (array) ( $settings['design'] ?? [] );
        $title = esc_html( (string) ( $form['name'] ?? 'Form' ) );
        $description = esc_html( (string) ( $form['description'] ?? '' ) );
        $asset_css = \metis_module_asset_url( 'forms', 'forms.css' );
        $asset_js = \metis_module_asset_url( 'forms', 'forms.js' );
        $config = [
            'mode' => 'public',
            'form' => [
                'id' => (int) ( $form['id'] ?? 0 ),
                'slug' => (string) ( $form['slug'] ?? '' ),
                'name' => (string) ( $form['name'] ?? '' ),
                'schema' => $schema,
                'settings' => $settings,
                'submit_url' => Support::publicUrl( (string) ( $form['slug'] ?? '' ) ),
            ],
            'result' => $result,
        ];

        $style_vars = sprintf(
            '--metis-form-accent:%s;--metis-form-button-bg:%s;--metis-form-button-text:%s;--metis-form-radius:%spx;',
            esc_attr( (string) ( $design['accent_color'] ?? '#126497' ) ),
            esc_attr( (string) ( $design['button_bg'] ?? '#126497' ) ),
            esc_attr( (string) ( $design['button_text'] ?? '#ffffff' ) ),
            esc_attr( (string) ( $design['field_radius'] ?? '14' ) )
        );
        $surface_class = 'metis-forms-public-card--' . esc_attr( (string) ( $design['surface_style'] ?? 'clean' ) );

        ob_start();
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $title; ?></title>
    <link rel="stylesheet" href="<?php echo esc_url( $asset_css ); ?>">
</head>
<body class="metis-forms-public-shell">
    <div class="metis-forms-public-wrap">
        <section class="metis-forms-public-card <?php echo $surface_class; ?>" data-metis-forms-public="1" style="<?php echo $style_vars; ?>">
            <div class="metis-forms-public-head">
                <p class="metis-forms-public-kicker">Metis Forms</p>
                <h1><?php echo $title; ?></h1>
                <?php if ( $description !== '' ) : ?>
                    <p><?php echo $description; ?></p>
                <?php endif; ?>
            </div>
            <div id="metis-forms-public-alert" class="mw-alert" <?php if ( empty( $result ) ) : ?>style="display:none"<?php endif; ?>>
                <?php echo ! empty( $result['message'] ) ? metis_kses_post( (string) $result['message'] ) : ''; ?>
            </div>
            <form id="metis-forms-public-form" class="metis-forms-public-form" enctype="multipart/form-data" method="post" action="<?php echo esc_url( Support::publicUrl( (string) ( $form['slug'] ?? '' ) ) ); ?>">
                <?php foreach ( $schema as $field ) : ?>
                    <?php self::renderField( is_array( $field ) ? $field : [], $settings ); ?>
                <?php endforeach; ?>
                <div class="metis-forms-public-actions">
                    <button class="mw-btn" type="submit">Submit</button>
                </div>
            </form>
        </section>
    </div>
    <script id="metis-forms-public-data" type="application/json"><?php echo \metis_json_encode( $config ); ?></script>
    <script src="<?php echo esc_url( $asset_js ); ?>"></script>
</body>
</html>
        <?php
        return Response::html( (string) ob_get_clean() );
    }

    private static function renderField(array $field, array $settings = [], string $name_prefix = '', string $dom_prefix = ''): void {
        $key = (string) ( $field['key'] ?? '' );
        if ( $key === '' ) {
            return;
        }

        $type = (string) ( $field['type'] ?? 'text' );
        $label = esc_html( (string) ( $field['label'] ?? $key ) );
        $help = esc_html( (string) ( $field['help'] ?? '' ) );
        $required = ! empty( $field['required'] );
        $input_name = $name_prefix !== '' ? $name_prefix . '[' . $key . ']' : $key;
        $dom_name = $dom_prefix !== '' ? $dom_prefix . '__' . $key : $key;
        $attrs = ' data-field-key="' . esc_attr( $key ) . '"';
        $attrs .= ! empty( $field['conditions'] ) ? ' data-conditions="' . esc_attr( \metis_json_encode( $field['conditions'] ) ?: '[]' ) . '"' : '';
        $attrs .= ' data-field-type="' . esc_attr( $type ) . '"';
        $attrs .= ' data-field-format="' . esc_attr( (string) ( $field['format'] ?? '' ) ) . '"';
        $attrs .= ! empty( $field['searchable'] ) ? ' data-searchable="1"' : '';
        $attrs .= ! empty( $field['depends_on'] ) ? ' data-depends-on="' . esc_attr( (string) $field['depends_on'] ) . '"' : '';
        $width_class = (string) ( $field['width'] ?? 'full' ) === 'half' ? ' is-half' : '';
        $validation = (array) ( $field['validation'] ?? [] );

        echo '<div class="metis-form-field' . esc_attr( $width_class ) . '"' . $attrs . '>';
        echo '<label for="field_' . esc_attr( $dom_name ) . '">' . $label . ( $required ? ' *' : '' ) . '</label>';
        if ( $help !== '' ) {
            echo '<p class="metis-form-help">' . $help . '</p>';
        }

        if ( $type === 'textarea' ) {
            echo '<textarea class="mw-input" id="field_' . esc_attr( $dom_name ) . '" name="' . esc_attr( $input_name ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '"';
            if ( ! empty( $validation['min_length'] ) ) {
                echo ' minlength="' . esc_attr( (string) $validation['min_length'] ) . '"';
            }
            if ( ! empty( $validation['max_length'] ) ) {
                echo ' maxlength="' . esc_attr( (string) $validation['max_length'] ) . '"';
            }
            echo '></textarea>';
        } elseif ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
            $options = (array) ( $field['options'] ?? [] );
            if ( ! empty( $field['options_source']['type'] ) ) {
                $options = Repository::resolveDynamicOptions( (array) ( $field['options_source'] ?? [] ) );
            }
            if ( $type === 'select' ) {
                if ( ! empty( $field['searchable'] ) ) {
                    echo '<input class="mw-input metis-form-select-search" type="text" data-select-search-for="' . esc_attr( $input_name ) . '" placeholder="Start typing to narrow the list">';
                }
                echo '<select class="mw-select" id="field_' . esc_attr( $dom_name ) . '" name="' . esc_attr( $input_name ) . '">';
                echo '<option value="">Select...</option>';
                foreach ( $options as $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    echo '<option value="' . esc_attr( (string) ( $option['value'] ?? '' ) ) . '" data-category="' . esc_attr( (string) ( $option['category'] ?? '' ) ) . '">' . esc_html( (string) ( $option['label'] ?? '' ) ) . '</option>';
                }
                echo '</select>';
            } else {
                $name = $type === 'checkbox' ? $input_name . '[]' : $input_name;
                foreach ( $options as $index => $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    $option_id = 'field_' . $dom_name . '_' . (int) $index;
                    echo '<label class="metis-form-choice" for="' . esc_attr( $option_id ) . '">';
                    echo '<input type="' . esc_attr( $type === 'checkbox' ? 'checkbox' : 'radio' ) . '" id="' . esc_attr( $option_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $option['value'] ?? '' ) ) . '">';
                    echo '<span>' . esc_html( (string) ( $option['label'] ?? '' ) ) . '</span>';
                    echo '</label>';
                }
            }
        } elseif ( $type === 'file' ) {
            echo '<input class="mw-input" type="file" id="field_' . esc_attr( $dom_name ) . '" name="' . esc_attr( $input_name ) . '">';
        } elseif ( $type === 'repeater' ) {
            $subfields = (array) ( $field['subfields'] ?? [] );
            $limit = max( 1, (int) ( $field['repeat_limit'] ?? 10 ) );
            echo '<div class="metis-form-repeater" data-repeater-key="' . esc_attr( $key ) . '" data-repeat-limit="' . esc_attr( (string) $limit ) . '">';
            echo '<div class="metis-form-repeater-rows"></div>';
            echo '<template>';
            echo '<div class="metis-form-repeater-row">';
            echo '<div class="metis-form-repeater-grid">';
            foreach ( $subfields as $subfield ) {
                self::renderField(
                    is_array( $subfield ) ? $subfield : [],
                    $settings,
                    $input_name . '[__INDEX__]',
                    $dom_name . '__INDEX__'
                );
            }
            echo '</div>';
            echo '<button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-remove-row>Remove</button>';
            echo '</div>';
            echo '</template>';
            echo '<button type="button" class="mw-btn mw-btn-xs" data-add-row>Add another</button>';
            echo '</div>';
        } elseif ( $type === 'payment' ) {
            echo '<div class="metis-form-payment-shell">';
            if ( ! empty( $settings['payments']['allow_discount_code'] ) ) {
                echo '<div class="metis-form-field">';
                echo '<label for="metis-form-discount">Discount code</label>';
                echo '<input class="mw-input" type="text" id="metis-form-discount" name="_discount_code" autocomplete="off">';
                echo '</div>';
            }
            echo '<div class="metis-form-totals" data-metis-form-totals>';
            echo '<div><span>Subtotal</span><strong data-total-subtotal>$0.00</strong></div>';
            echo '<div><span>Discount</span><strong data-total-discount>$0.00</strong></div>';
            echo '<div><span>Processing fee</span><strong data-total-fee>$0.00</strong></div>';
            echo '<div class="is-grand"><span>' . esc_html( (string) ( $settings['payments']['label'] ?? 'Total' ) ) . '</span><strong data-total-grand>$0.00</strong></div>';
            echo '</div>';
            echo '<div class="metis-forms-stripe-panel">';
            echo '<div class="metis-forms-sidebar-label">Complete payment</div>';
            echo '<div id="metis-forms-stripe-mount"></div>';
            echo '<button type="button" class="mw-btn" id="metis-forms-stripe-confirm">Pay now</button>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<input class="mw-input" type="' . esc_attr( in_array( $type, [ 'email', 'number', 'date' ], true ) ? $type : 'text' ) . '" id="field_' . esc_attr( $dom_name ) . '" name="' . esc_attr( $input_name ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '"';
            if ( (string) ( $field['min'] ?? '' ) !== '' ) {
                echo ' min="' . esc_attr( (string) $field['min'] ) . '"';
            }
            if ( (string) ( $field['max'] ?? '' ) !== '' ) {
                echo ' max="' . esc_attr( (string) $field['max'] ) . '"';
            }
            if ( ! empty( $validation['min_length'] ) ) {
                echo ' minlength="' . esc_attr( (string) $validation['min_length'] ) . '"';
            }
            if ( ! empty( $validation['max_length'] ) ) {
                echo ' maxlength="' . esc_attr( (string) $validation['max_length'] ) . '"';
            }
            echo '>';
        }

        echo '</div>';
    }
}

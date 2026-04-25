<?php
$formId = isset($data['form_id']) ? (string) $data['form_id'] : '';
echo '<div class="metis-block-form" data-form-id="' . metis_esc_attr($formId) . '"></div>';

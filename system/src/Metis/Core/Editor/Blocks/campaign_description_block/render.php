<?php
$campaignId = isset($data['campaign_id']) ? (string) $data['campaign_id'] : '';
echo '<div class="metis-block-campaign-description" data-campaign-id="' . metis_esc_attr($campaignId) . '"></div>';

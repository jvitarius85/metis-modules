<?php
$campaignId = isset($data['campaign_id']) ? (string) $data['campaign_id'] : '';
echo '<div class="metis-block-progress" data-campaign-id="' . metis_esc_attr($campaignId) . '"></div>';

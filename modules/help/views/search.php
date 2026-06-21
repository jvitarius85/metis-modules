<?php
if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}
?>
<div id="metis-help-search-modal" class="metis-modal-backdrop help-search-modal" aria-hidden="true">
    <div class="metis-modal help-search-dialog" role="dialog" aria-modal="true" aria-labelledby="metis-help-search-title">
        <div class="metis-modal-header">
            <h2 id="metis-help-search-title">Help Search</h2>
            <button type="button" class="metis-modal-close" data-help-search-close="1" aria-label="Close help search">&times;</button>
        </div>
        <div class="metis-modal-body">
            <div class="help-search-container" data-help-search-root="1">
                <div class="help-search-bar">
                    <label class="screen-reader-text" for="helpSearchInput">Search help</label>
                    <input type="text" id="helpSearchInput" class="metis-input" placeholder="Search help..." autocomplete="off">
                    <label class="screen-reader-text" for="helpSearchCategory">Filter help category</label>
                    <select id="helpSearchCategory" class="metis-input">
                        <option value="">All categories</option>
                    </select>
                </div>
                <div id="helpSearchLoading" class="help-loading hidden" aria-live="polite">Searching...</div>
                <div id="helpSearchResults" class="help-results" aria-live="polite"></div>
                <div id="helpSearchEmpty" class="help-empty hidden">No results found</div>
            </div>
        </div>
    </div>
</div>

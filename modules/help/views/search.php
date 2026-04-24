<?php
if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}
?>
<div id="metis-help-search-modal" class="mw-modal-backdrop help-search-modal" aria-hidden="true">
    <div class="mw-modal help-search-dialog" role="dialog" aria-modal="true" aria-labelledby="metis-help-search-title">
        <div class="mw-modal-header">
            <h2 id="metis-help-search-title">Help Search</h2>
            <button type="button" class="mw-modal-close" data-help-search-close="1" aria-label="Close help search">&times;</button>
        </div>
        <div class="mw-modal-body">
            <div class="help-search-container" data-help-search-root="1">
                <div class="help-search-bar">
                    <label class="screen-reader-text" for="helpSearchInput">Search help</label>
                    <input type="text" id="helpSearchInput" class="mw-input" placeholder="Search help..." autocomplete="off">
                    <label class="screen-reader-text" for="helpSearchCategory">Filter help category</label>
                    <select id="helpSearchCategory" class="mw-input">
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

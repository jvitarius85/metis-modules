/**
 * Donations Module JS
 *
 * Handles:
 *   donor.php       — sortable giving history + pagination + clickable rows
 *   donors.php      — search/filter, pagination, clickable rows
 *   transactions.php — filters, sorting, batch selection, CSV export, keyboard nav
 */

function initMetisDonationsModule( context ) {
    const scope = context && context.root ? context.root : document;
    const hasDonationsUi = scope.querySelector( '.mw-donor-view, .mw-donor-row, .mw-tx-row, .mw-campaigns-table' );
    if ( ! hasDonationsUi ) return;
    if ( scope !== document && scope.getAttribute( 'data-metis-donations-initialized' ) === '1' ) return;
    if ( scope !== document ) scope.setAttribute( 'data-metis-donations-initialized', '1' );

    function navigate(url) {
        var target = String( url || '' ).trim();
        if ( ! target ) return false;
        if ( window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function' ) {
            return Metis.navigation.go( target );
        }
        window.location.assign( target );
        return true;
    }

    // =========================================================================
    // DONOR DETAIL (donor.php)
    // =========================================================================

    const donorView = document.querySelector( '.mw-donor-view' );

    if ( donorView ) {
        const rowsContainer = document.getElementById( 'mw-donor-tx-rows' );
        if ( ! rowsContainer ) return;

        const allRows = Array.from( rowsContainer.querySelectorAll( '.mw-donor-tx-row' ) );
        if ( ! allRows.length ) return;

        let sortKey     = 'date';
        let sortDir     = 'desc';
        let currentPage = 1;
        const PER_PAGE  = 25;

        const prevBtn  = document.getElementById( 'mw-donor-prev' );
        const nextBtn  = document.getElementById( 'mw-donor-next' );
        const statusEl = document.getElementById( 'mw-donor-page-status' );
        const sortBtns = donorView.querySelectorAll( '.mw-sort-btn' );

        function getVal( row, key ) {
            if ( key === 'amount' ) return parseFloat( row.dataset.amount || '0' ) || 0;
            return row.dataset[ key ] || '';
        }

        function applySort() {
            const rows = [ ...allRows ];
            rows.sort( ( a, b ) => {
                const va = getVal( a, sortKey );
                const vb = getVal( b, sortKey );
                if ( sortKey === 'amount' ) return sortDir === 'asc' ? va - vb : vb - va;
                if ( va < vb ) return sortDir === 'asc' ? -1 : 1;
                if ( va > vb ) return sortDir === 'asc' ?  1 : -1;
                return 0;
            } );
            rows.forEach( r => rowsContainer.appendChild( r ) );
            sortBtns.forEach( btn => {
                btn.classList.remove( 'mw-sort-asc', 'mw-sort-desc', 'mw-sort-active' );
                if ( btn.dataset.sort === sortKey ) {
                    btn.classList.add( 'mw-sort-active', sortDir === 'asc' ? 'mw-sort-asc' : 'mw-sort-desc' );
                }
            } );
            applyPagination();
        }

        sortBtns.forEach( btn => {
            btn.style.cursor = 'pointer';
            btn.addEventListener( 'click', () => {
                const key = btn.dataset.sort;
                if ( ! key ) return;
                if ( sortKey === key ) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    sortDir = ( key === 'date' || key === 'amount' ) ? 'desc' : 'asc';
                }
                currentPage = 1;
                applySort();
            } );
        } );

        function applyPagination() {
            const total = allRows.length;
            const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
            if ( currentPage > pages ) currentPage = pages;
            allRows.forEach( ( row, idx ) => {
                const start = ( currentPage - 1 ) * PER_PAGE;
                row.style.display = ( idx >= start && idx < start + PER_PAGE ) ? '' : 'none';
            } );
            if ( prevBtn )  prevBtn.disabled  = currentPage <= 1;
            if ( nextBtn )  nextBtn.disabled  = currentPage >= pages;
            if ( statusEl ) statusEl.textContent = `Page ${ currentPage } of ${ pages }`;
        }

        if ( prevBtn ) prevBtn.addEventListener( 'click', () => { currentPage--; applyPagination(); } );
        if ( nextBtn ) nextBtn.addEventListener( 'click', () => { currentPage++; applyPagination(); } );

        allRows.forEach( row => {
            const href = row.dataset.href;
            if ( ! href ) return;
            row.style.cursor = 'pointer';
            row.addEventListener( 'click', () => { navigate( href ); } );
            row.addEventListener( 'keydown', e => {
                if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); navigate( href ); }
            } );
        } );

        applySort();
    }

    // =========================================================================
    // DONORS LIST (donors.php)
    // =========================================================================

    const donorRows = Array.from( document.querySelectorAll( '.mw-donor-row' ) );

    if ( donorRows.length ) {
        const search     = document.getElementById( 'mw-donor-search' );
        const givingFilt = document.getElementById( 'mw-donor-giving' );
        const prevBtn    = document.getElementById( 'mw-page-prev' );
        const nextBtn    = document.getElementById( 'mw-page-next' );
        const indicator  = document.getElementById( 'mw-page-indicator' );

        const PER_PAGE = 50;
        let currentPage = 1;

        function norm( s ) { return ( s || '' ).toLowerCase().trim(); }

        function getFilteredRows() {
            const q      = norm( search ? search.value : '' );
            const giving = givingFilt ? givingFilt.value : 'all';
            return donorRows.filter( row => {
                const name  = norm( row.dataset.name  || '' );
                const email = norm( row.dataset.email || '' );
                const did   = norm( row.dataset.did   || '' );
                const total = parseFloat( row.dataset.total || '0' );
                if ( q && ! name.includes( q ) && ! email.includes( q ) && ! did.includes( q ) ) return false;
                if ( giving === 'with_gifts' && total <= 0 ) return false;
                if ( giving === 'no_gifts'   && total > 0  ) return false;
                return true;
            } );
        }

        function renderDonors() {
            const filtered   = getFilteredRows();
            const totalPages = Math.max( 1, Math.ceil( filtered.length / PER_PAGE ) );
            if ( currentPage > totalPages ) currentPage = totalPages;
            const start = ( currentPage - 1 ) * PER_PAGE;
            const end   = start + PER_PAGE;
            donorRows.forEach( r => r.style.display = 'none' );
            filtered.forEach( ( r, i ) => { r.style.display = ( i >= start && i < end ) ? '' : 'none'; } );
            if ( indicator ) indicator.textContent = `Page ${ currentPage } of ${ totalPages }`;
            if ( prevBtn )   prevBtn.disabled = currentPage <= 1;
            if ( nextBtn )   nextBtn.disabled = currentPage >= totalPages;
        }

        donorRows.forEach( row => {
            row.style.cursor = 'pointer';
            row.addEventListener( 'click', function ( e ) {
                if ( e.target.closest( 'a, button' ) ) return;
                const href = row.dataset.href;
                if ( href ) navigate( href );
            } );
        } );

        if ( search )     search.addEventListener( 'input',   () => { currentPage = 1; renderDonors(); } );
        if ( givingFilt ) givingFilt.addEventListener( 'change', () => { currentPage = 1; renderDonors(); } );
        if ( prevBtn )    prevBtn.addEventListener( 'click',   () => { currentPage--; renderDonors(); } );
        if ( nextBtn )    nextBtn.addEventListener( 'click',   () => { currentPage++; renderDonors(); } );

        renderDonors();
    }

    // =========================================================================
    // TRANSACTIONS LIST (transactions.php)
    // =========================================================================

    const offlineDonationForm = document.querySelector( '.mw-offline-donation-form' );
    if ( offlineDonationForm ) {
        const modal         = document.getElementById( 'mw-offline-donation-modal' );
        const openBtn       = document.getElementById( 'mw-open-offline-donation-modal' );
        const closeBtn      = document.getElementById( 'mw-offline-donation-close' );
        const cancelBtn     = document.getElementById( 'mw-offline-donation-cancel' );
        const methodSelect  = offlineDonationForm.querySelector( '[data-offline-method]' );
        const checkField    = offlineDonationForm.querySelector( '[data-offline-check-field]' );
        const checkInput    = checkField ? checkField.querySelector( 'input[name="chk_num"]' ) : null;
        const lookupInput   = document.getElementById( 'mw-offline-donor-lookup' );
        const donorDidInput = document.getElementById( 'mw-offline-donor-did' );
        const donorStatus   = document.getElementById( 'mw-offline-donor-status' );
        const donorResults  = document.getElementById( 'mw-offline-donor-results' );
        const firstName     = offlineDonationForm.querySelector( 'input[name="first_name"]' );
        const lastName      = offlineDonationForm.querySelector( 'input[name="last_name"]' );
        const emailInput    = offlineDonationForm.querySelector( 'input[name="email"]' );
        const phoneInput    = offlineDonationForm.querySelector( 'input[name="phone"]' );
        const lookupNonce   = lookupInput ? String( lookupInput.getAttribute( 'data-lookup-nonce' ) || '' ) : '';

        let lookupTimer = null;
        let selectedDonorLabel = '';

        function openOfflineModal() {
            if ( ! modal ) return;
            if ( window.MetisCore && MetisCore.modal && typeof MetisCore.modal.open === 'function' ) {
                MetisCore.modal.open( 'mw-offline-donation-modal' );
            } else {
                modal.style.display = 'block';
                modal.setAttribute( 'aria-hidden', 'false' );
            }
            document.body.classList.add( 'mw-modal-open' );
            setTimeout( function () {
                if ( lookupInput ) lookupInput.focus();
            }, 0 );
        }

        function closeOfflineModal() {
            if ( ! modal ) return;
            if ( window.MetisCore && MetisCore.modal && typeof MetisCore.modal.close === 'function' ) {
                MetisCore.modal.close( 'mw-offline-donation-modal' );
            } else {
                modal.style.display = 'none';
                modal.setAttribute( 'aria-hidden', 'true' );
            }
            document.body.classList.remove( 'mw-modal-open' );
            hideLookupResults();
        }

        function hideLookupResults() {
            if ( donorResults ) {
                donorResults.hidden = true;
                donorResults.innerHTML = '';
            }
        }

        function donorFullName() {
            return [ firstName?.value || '', lastName?.value || '' ].join( ' ' ).trim();
        }

        function syncDonorStatus() {
            if ( ! donorStatus ) return;
            if ( donorDidInput && donorDidInput.value ) {
                donorStatus.textContent = 'Using existing donor: ' + ( selectedDonorLabel || donorDidInput.value );
                donorStatus.dataset.mode = 'selected';
                return;
            }

            const hasDraftIdentity = donorFullName() || ( emailInput && emailInput.value.trim() ) || ( phoneInput && phoneInput.value.trim() );
            donorStatus.textContent = hasDraftIdentity
                ? 'No existing donor selected. A new donor will be created when you save.'
                : 'No donor selected. Search for a donor or enter new donor details.';
            donorStatus.dataset.mode = hasDraftIdentity ? 'new' : 'empty';
        }

        function clearSelectedDonor() {
            if ( donorDidInput ) donorDidInput.value = '';
            selectedDonorLabel = '';
            syncDonorStatus();
        }

        function selectDonor( donor ) {
            if ( ! donor ) return;
            if ( donorDidInput ) donorDidInput.value = donor.did || '';
            if ( lookupInput ) lookupInput.value = donor.name || donor.did || '';
            if ( firstName && donor.first_name !== undefined ) firstName.value = donor.first_name || '';
            if ( lastName && donor.last_name !== undefined ) lastName.value = donor.last_name || '';
            if ( emailInput && donor.email !== undefined ) emailInput.value = donor.email || '';
            if ( phoneInput && donor.phone !== undefined && ! phoneInput.value ) phoneInput.value = donor.phone || '';
            selectedDonorLabel = donor.name || donor.did || '';
            hideLookupResults();
            syncDonorStatus();
        }

        function renderLookupResults( matches ) {
            if ( ! donorResults ) return;
            if ( ! Array.isArray( matches ) || ! matches.length ) {
                donorResults.hidden = false;
                donorResults.innerHTML = '<div class="mw-offline-donor-empty">No matching donors found. Continue entering a new donor.</div>';
                return;
            }

            donorResults.hidden = false;
            donorResults.innerHTML = matches.map( function (item, index) {
                const meta = [];
                if ( item.email ) meta.push( Metis.util.escapeHtml( item.email ) );
                if ( item.did ) meta.push( 'DID: ' + Metis.util.escapeHtml( item.did ) );
                meta.push( Metis.util.escapeHtml( String( item.gift_count || 0 ) + ' gifts' ) );
                meta.push( Metis.util.escapeHtml( '$' + Number( item.total_raised || 0 ).toFixed( 2 ) ) );
                return '' +
                    '<button type="button" class="mw-offline-donor-result" data-index="' + index + '">' +
                        '<strong>' + Metis.util.escapeHtml( item.name || item.did || 'Donor' ) + '</strong>' +
                        '<span>' + meta.join( ' · ' ) + '</span>' +
                    '</button>';
            } ).join( '' );

            donorResults.querySelectorAll( '.mw-offline-donor-result' ).forEach( function (button) {
                button.addEventListener( 'click', function () {
                    const idx = parseInt( button.getAttribute( 'data-index' ) || '-1', 10 );
                    if ( idx >= 0 && matches[ idx ] ) {
                        selectDonor( matches[ idx ] );
                    }
                } );
            } );
        }

        function runDonorLookup() {
            if ( ! lookupInput || ! donorResults ) return;
            const query = String( lookupInput.value || '' ).trim();
            if ( query.length < 2 ) {
                hideLookupResults();
                syncDonorStatus();
                return;
            }

            donorResults.hidden = false;
            donorResults.innerHTML = '<div class="mw-offline-donor-empty">Searching donors…</div>';

            const body = new FormData();
            body.append( 'action', 'metis_donations_lookup_donors' );
            body.append( 'nonce', lookupNonce );
            body.append( 'q', query );

            fetch( metisAjax.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' } )
                .then( function (res) { return res.json(); } )
                .then( function (res) {
                    if ( ! res || ! res.success ) {
                        throw new Error( res && res.data && res.data.message ? res.data.message : 'Lookup failed.' );
                    }
                    renderLookupResults( res.data && Array.isArray( res.data.matches ) ? res.data.matches : [] );
                } )
                .catch( function (error) {
                    donorResults.hidden = false;
                    donorResults.innerHTML = '<div class="mw-offline-donor-empty mw-offline-donor-empty-error">' + Metis.util.escapeHtml( error.message || 'Lookup failed.' ) + '</div>';
                } );
        }

        function queueDonorLookup() {
            if ( lookupTimer ) {
                clearTimeout( lookupTimer );
            }
            lookupTimer = setTimeout( runDonorLookup, 180 );
        }

        function syncOfflineDonationForm() {
            const method = ( methodSelect && methodSelect.value ? methodSelect.value : '' ).toLowerCase();
            const showCheck = method === 'ck';
            if ( checkField ) {
                checkField.style.display = showCheck ? '' : 'none';
            }
            if ( checkInput && ! showCheck ) {
                checkInput.value = '';
            }
        }

        [ firstName, lastName, emailInput, phoneInput ].forEach( function (field) {
            if ( ! field ) return;
            field.addEventListener( 'input', syncDonorStatus );
        } );

        if ( lookupInput ) {
            lookupInput.addEventListener( 'input', function () {
                clearSelectedDonor();
                queueDonorLookup();
            } );
            lookupInput.addEventListener( 'focus', function () {
                if ( String( lookupInput.value || '' ).trim().length >= 2 ) {
                    queueDonorLookup();
                }
            } );
        }

        if ( methodSelect ) {
            methodSelect.addEventListener( 'change', syncOfflineDonationForm );
            syncOfflineDonationForm();
        }
        if ( openBtn ) openBtn.addEventListener( 'click', openOfflineModal );
        if ( closeBtn ) closeBtn.addEventListener( 'click', closeOfflineModal );
        if ( cancelBtn ) cancelBtn.addEventListener( 'click', closeOfflineModal );
        if ( modal ) {
            modal.addEventListener( 'click', function (event) {
                if ( event.target === modal ) closeOfflineModal();
            } );
            if ( modal.getAttribute( 'data-auto-open' ) === '1' ) {
                openOfflineModal();
            }
        }
        document.addEventListener( 'keydown', function (event) {
            if ( event.key === 'Escape' && modal && modal.getAttribute( 'aria-hidden' ) === 'false' ) {
                closeOfflineModal();
            }
        } );

        syncDonorStatus();
        if ( donorDidInput && donorDidInput.value ) {
            selectedDonorLabel = donorFullName() || donorDidInput.value;
        }
        syncDonorStatus();
    }

    const txRows = Array.from( document.querySelectorAll( '.mw-tx-row' ) );

    if ( txRows.length ) {
        const tableContainer = document.querySelector( '.mw-tx-table' );
        const statusFilter   = document.getElementById( 'mw-status-filter' );
        const campaignFilter = document.getElementById( 'mw-campaign-filter' );
        const methodFilter   = document.getElementById( 'mw-method-filter' );
        const dateStartInput = document.getElementById( 'mw-date-start' );
        const dateEndInput   = document.getElementById( 'mw-date-end' );
        const searchInput    = document.getElementById( 'mw-search' );
        const quickButtons   = document.querySelectorAll( '.mw-pill-btn' );
        const sortSelect     = document.getElementById( 'mw-sort' );
        const exportBtn      = document.getElementById( 'mw-export-csv' );
        const badgesWrap     = document.getElementById( 'mw-active-filters' );
        const selectAll      = document.getElementById( 'mw-tx-select-all' );
        const txCheckboxes   = Array.from( document.querySelectorAll( '.mw-tx-checkbox' ) );
        const batchCount     = document.getElementById( 'mw-batch-count' );
        const batchTotal     = document.getElementById( 'mw-batch-total' );
        const batchSubmit    = document.getElementById( 'mw-create-batch-btn' );

        let focusedIndex = -1;

        function norm( s ) { return ( s || '' ).toLowerCase().trim(); }
        function titleCase( s ) { return norm( s ).split( ' ' ).filter( Boolean ).map( w => w[0].toUpperCase() + w.slice(1) ).join( ' ' ); }
        function parseIsoDate( iso ) { return iso ? new Date( iso + 'T00:00:00' ) : null; }
        function formatMoney( n ) { return n.toLocaleString( 'en-US', { style: 'currency', currency: 'USD' } ); }
        function getVisibleRows() { return txRows.filter( r => r.style.display !== 'none' ); }

        function parseUserDate( str ) {
            if ( ! str ) return null;
            const parts = str.split( '/' );
            if ( parts.length !== 3 ) return null;
            let [ mm, dd, yy ] = parts.map( Number );
            if ( ! mm || ! dd || ! yy ) return null;
            yy = yy < 50 ? 2000 + yy : 1900 + yy;
            const d = new Date( `${ String(yy).padStart(4,'0') }-${ String(mm).padStart(2,'0') }-${ String(dd).padStart(2,'0') }` );
            return isNaN( d ) ? null : d;
        }

        function formatUserDate( d ) {
            if ( ! ( d instanceof Date ) || isNaN( d ) ) return '';
            return `${ String( d.getMonth()+1 ).padStart(2,'0') }/${ String( d.getDate() ).padStart(2,'0') }/${ String( d.getFullYear() ).slice(-2) }`;
        }

        // Build filter dropdowns from row data
        const campaignSet = new Set(), methodSet = new Set();
        txRows.forEach( r => {
            if ( r.dataset.campaign ) campaignSet.add( norm( r.dataset.campaign ) );
            if ( r.dataset.method )   methodSet.add( norm( r.dataset.method ) );
        } );

        if ( campaignFilter ) {
            campaignFilter.innerHTML = '<option value="">All Campaigns</option>' +
                Array.from( campaignSet ).sort().map( c => `<option value="${ c }">${ titleCase( c ) }</option>` ).join( '' );
        }
        if ( methodFilter ) {
            methodFilter.innerHTML = '<option value="">All Methods</option>' +
                Array.from( methodSet ).sort().map( m => `<option value="${ m }">${ titleCase( m ) }</option>` ).join( '' );
        }

        // Date mask
        function maskDate( input ) {
            if ( ! input ) return;
            input.addEventListener( 'input', function () {
                let v = input.value.replace( /\D/g, '' );
                if ( v.length > 2 ) v = v.slice(0,2) + '/' + v.slice(2);
                if ( v.length > 5 ) v = v.slice(0,5) + '/' + v.slice(5);
                input.value = v.slice(0,8);
                applyFilters();
            } );
        }
        maskDate( dateStartInput );
        maskDate( dateEndInput );

        function applyFilters() {
            const fStatus   = statusFilter   ? statusFilter.value   : 'all';
            const fCampaign = campaignFilter ? norm( campaignFilter.value ) : '';
            const fMethod   = methodFilter   ? norm( methodFilter.value )   : '';
            const fSearch   = norm( searchInput ? searchInput.value : '' );
            const startDate = dateStartInput ? parseUserDate( dateStartInput.value ) : null;
            const endDate   = dateEndInput   ? parseUserDate( dateEndInput.value )   : null;

            txRows.forEach( row => {
                let show    = true;
                const dep   = row.dataset.deposited;
                const rowDate = parseIsoDate( row.dataset.date );
                if ( fStatus === 'undeposited' && dep === 'yes' ) show = false;
                if ( fStatus === 'deposited'   && dep === 'no' )  show = false;
                if ( fCampaign && ! norm( row.dataset.campaign ).includes( fCampaign ) ) show = false;
                if ( fMethod   && ! norm( row.dataset.method ).includes( fMethod ) )     show = false;
                if ( fSearch   && ! norm( row.innerText ).includes( fSearch ) )          show = false;
                if ( startDate && rowDate && rowDate < startDate ) show = false;
                if ( endDate   && rowDate && rowDate > endDate )   show = false;
                row.style.display = show ? 'grid' : 'none';
                row.classList.remove( 'mw-tx-row--focused' );
            } );

            focusedIndex = -1;
            applySort();
            updateBadges();
            refreshBatchSummary();
        }

        function applySort() {
            if ( ! sortSelect || ! tableContainer ) return;
            const mode    = sortSelect.value || 'date_desc';
            const visible = getVisibleRows().slice();
            visible.sort( ( a, b ) => {
                const aD = parseIsoDate( a.dataset.date ), bD = parseIsoDate( b.dataset.date );
                const aA = parseFloat( a.dataset.amount || '0' ), bA = parseFloat( b.dataset.amount || '0' );
                if ( mode === 'date_asc' )    return aD - bD;
                if ( mode === 'date_desc' )   return bD - aD;
                if ( mode === 'amount_asc' )  return aA - bA;
                if ( mode === 'amount_desc' ) return bA - aA;
                return 0;
            } );
            visible.forEach( r => tableContainer.appendChild( r ) );
        }

        function updateBadges() {
            if ( ! badgesWrap ) return;
            const badges = [];
            if ( statusFilter   && statusFilter.value   !== 'all' ) badges.push( { key: 'status',   label: 'Status: '   + titleCase( statusFilter.value ) } );
            if ( campaignFilter && campaignFilter.value )           badges.push( { key: 'campaign', label: 'Campaign: ' + campaignFilter.options[ campaignFilter.selectedIndex ].text } );
            if ( methodFilter   && methodFilter.value )             badges.push( { key: 'method',   label: 'Method: '   + methodFilter.options[ methodFilter.selectedIndex ].text } );
            if ( searchInput    && searchInput.value.trim() )       badges.push( { key: 'search',   label: `Search: "${ searchInput.value.trim() }"` } );
            if ( ( dateStartInput && dateStartInput.value ) || ( dateEndInput && dateEndInput.value ) ) {
                badges.push( { key: 'date', label: `Date: ${ dateStartInput?.value || '…' } → ${ dateEndInput?.value || '…' }` } );
            }
            badgesWrap.innerHTML = '';
            badgesWrap.style.display = badges.length ? 'flex' : 'none';
            badges.forEach( b => {
                const el = document.createElement( 'button' );
                el.type = 'button'; el.className = 'mw-filter-badge'; el.dataset.filter = b.key;
                el.innerHTML = `${ b.label } <span class="mw-badge-x">×</span>`;
                badgesWrap.appendChild( el );
            } );
        }

        if ( badgesWrap ) {
            badgesWrap.addEventListener( 'click', function ( e ) {
                const badge = e.target.closest( '.mw-filter-badge' );
                if ( ! badge ) return;
                const key = badge.dataset.filter;
                if ( key === 'status'   && statusFilter )   statusFilter.value   = 'all';
                if ( key === 'campaign' && campaignFilter )  campaignFilter.value = '';
                if ( key === 'method'   && methodFilter )    methodFilter.value   = '';
                if ( key === 'search'   && searchInput )     searchInput.value    = '';
                if ( key === 'date' ) {
                    if ( dateStartInput ) dateStartInput.value = '';
                    if ( dateEndInput )   dateEndInput.value   = '';
                }
                applyFilters();
            } );
        }

        quickButtons.forEach( btn => {
            btn.addEventListener( 'click', function () {
                const range = btn.dataset.range;
                const now   = new Date();
                let start, end;
                if ( range === 'today' ) {
                    start = end = new Date( now.getFullYear(), now.getMonth(), now.getDate() );
                } else if ( range === 'week' ) {
                    end   = new Date( now.getFullYear(), now.getMonth(), now.getDate() );
                    start = new Date( end ); start.setDate( end.getDate() - end.getDay() );
                } else if ( range === 'month' ) {
                    start = new Date( now.getFullYear(), now.getMonth(), 1 );
                    end   = new Date( now.getFullYear(), now.getMonth() + 1, 0 );
                } else {
                    [ statusFilter, campaignFilter, methodFilter ].forEach( s => { if ( s ) s.value = s === statusFilter ? 'all' : ''; } );
                    if ( searchInput )    searchInput.value    = '';
                    if ( dateStartInput ) dateStartInput.value = '';
                    if ( dateEndInput )   dateEndInput.value   = '';
                    applyFilters(); return;
                }
                if ( dateStartInput ) dateStartInput.value = formatUserDate( start );
                if ( dateEndInput )   dateEndInput.value   = formatUserDate( end );
                applyFilters();
            } );
        } );

        // CSV export
        if ( exportBtn ) {
            exportBtn.addEventListener( 'click', function () {
                const visible = getVisibleRows();
                if ( ! visible.length ) { Metis.util.notify( 'No transactions to export.', 'warning' ); return; }
                const csvRows = [ [ 'TID', 'Date', 'Donor', 'Campaign', 'Amount', 'Status', 'Deposited' ] ];
                visible.forEach( row => {
                    csvRows.push( [
                        row.dataset.tid     || '',
                        ( row.querySelector( '.mw-tx-date' )     || {} ).textContent || '',
                        ( row.querySelector( '.mw-tx-donor' )    || {} ).textContent || '',
                        ( row.querySelector( '.mw-tx-campaign' ) || {} ).textContent || '',
                        ( row.querySelector( '.mw-tx-amount' )   || {} ).textContent || '',
                        row.dataset.status    || '',
                        row.dataset.deposited === 'yes' ? 'Deposited' : 'Not Deposited',
                    ] );
                } );
                const csv  = csvRows.map( c => c.map( v => `"${ String(v).replace( /"/g, '""' ) }"` ).join( ',' ) ).join( '\r\n' );
                const blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
                const url  = URL.createObjectURL( blob );
                const a    = document.createElement( 'a' );
                a.href = url; a.download = `transactions-${ new Date().toISOString().slice(0,10) }.csv`;
                document.body.appendChild( a ); a.click();
                document.body.removeChild( a ); URL.revokeObjectURL( url );
            } );
        }

        // Batch selection
        function refreshBatchSummary() {
            let count = 0, total = 0;
            txCheckboxes.forEach( cb => {
                if ( cb.checked ) {
                    count++;
                    total += parseFloat( cb.closest( '.mw-tx-row' )?.dataset.amount || '0' ) || 0;
                }
            } );
            if ( batchCount ) batchCount.textContent = count;
            if ( batchTotal ) batchTotal.textContent = formatMoney( total );
            if ( batchSubmit ) batchSubmit.disabled  = count === 0;
        }

        txCheckboxes.forEach( cb => {
            cb.addEventListener( 'click',  e => e.stopPropagation() );
            cb.addEventListener( 'change', refreshBatchSummary );
        } );

        if ( selectAll ) {
            selectAll.addEventListener( 'change', function () {
                txCheckboxes.forEach( cb => { cb.checked = selectAll.checked; } );
                refreshBatchSummary();
            } );
        }

        // Keyboard nav
        function focusRow( idx ) {
            const visible = getVisibleRows();
            visible.forEach( r => r.classList.remove( 'mw-tx-row--focused' ) );
            if ( ! visible.length ) { focusedIndex = -1; return; }
            idx = Math.max( 0, Math.min( idx, visible.length - 1 ) );
            visible[ idx ].classList.add( 'mw-tx-row--focused' );
            visible[ idx ].scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
            focusedIndex = idx;
        }

        scope.addEventListener( 'keydown', function ( e ) {
            const tag    = ( e.target.tagName || '' ).toLowerCase();
            const typing = [ 'input', 'textarea', 'select' ].includes( tag );
            if ( e.key === '/' && ! typing ) { e.preventDefault(); if ( searchInput ) searchInput.focus(); return; }
            if ( e.key === 'Escape' && document.activeElement === searchInput ) { searchInput.value = ''; applyFilters(); return; }
            if ( typing ) return;
            if ( e.key === 'ArrowDown' ) { e.preventDefault(); focusRow( focusedIndex + 1 ); }
            if ( e.key === 'ArrowUp' )   { e.preventDefault(); focusRow( focusedIndex - 1 ); }
            if ( e.key === 'Enter' && focusedIndex >= 0 ) {
                const link = getVisibleRows()[ focusedIndex ]?.querySelector( '.mw-tx-main-link' );
                if ( link?.href ) navigate( link.href );
            }
        } );

        // Event wiring
        [ statusFilter, campaignFilter, methodFilter ].forEach( el => {
            if ( el ) el.addEventListener( 'change', applyFilters );
        } );
        if ( sortSelect )  sortSelect.addEventListener( 'change', applySort );
        if ( searchInput ) searchInput.addEventListener( 'input', applyFilters );

        applyFilters();
        refreshBatchSummary();
    }

    // =========================================================
    // CAMPAIGNS LIST
    // =========================================================

    const campaignsTable = document.querySelector( '.mw-campaigns-table' );

    if ( campaignsTable ) {

        const searchInput  = document.getElementById( 'mw-campaign-search' );
        const typeFilter   = document.getElementById( 'mw-campaign-type-filter' );
        const statusFilter = document.getElementById( 'mw-campaign-status-filter' );
        const rows         = Array.from( document.querySelectorAll( '.mw-campaign-row[data-name]' ) );

        function filterCampaigns() {
            const q      = ( searchInput?.value || '' ).toLowerCase();
            const type   = ( typeFilter?.value   || '' ).toLowerCase();
            const status = ( statusFilter?.value || '' ).toLowerCase();

            rows.forEach( row => {
                const matchName   = ! q      || row.dataset.name.includes( q );
                const matchType   = ! type   || row.dataset.type === type;
                const matchStatus = ! status || row.dataset.active === status;
                row.style.display = ( matchName && matchType && matchStatus ) ? '' : 'none';
            } );
        }

        if ( searchInput  ) searchInput.addEventListener( 'input',  filterCampaigns );
        if ( typeFilter   ) typeFilter.addEventListener(  'change', filterCampaigns );
        if ( statusFilter ) statusFilter.addEventListener( 'change', filterCampaigns );

        // Row click → campaign detail
        rows.forEach( row => {
            row.addEventListener( 'click', () => {
                if ( row.dataset.href ) navigate( row.dataset.href );
            } );
        } );
    }

}

document.addEventListener( 'DOMContentLoaded', function () {
    initMetisDonationsModule( { root: document, reason: 'dom-ready', url: window.location.href } );
} );

if ( window.Metis && Metis.page && typeof Metis.page.register === 'function' ) {
    Metis.page.register( 'donations', initMetisDonationsModule );
}

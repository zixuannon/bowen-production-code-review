<style>
    .central-finance-page { --cf-navy: #1f5f87; --cf-border: #e4eaf0; --cf-muted: #6c7a89; --cf-surface: #fff; }
    .central-finance-page .card { border: 1px solid var(--cf-border); border-radius: .6rem; box-shadow: none; }
    .central-finance-page .card-body { padding: 1.15rem; }
    .cf-page-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; padding: 1rem 1.15rem; border: 1px solid var(--cf-border); border-radius: .6rem; background: var(--cf-surface); }
    .cf-page-header__eyebrow { margin: 0 0 .2rem; color: var(--cf-navy); font-size: .75rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .cf-page-header__title { margin: 0; color: #243746; font-size: 1.25rem; font-weight: 700; }
    .cf-page-header__description { margin: .3rem 0 0; color: var(--cf-muted); font-size: .875rem; }
    .cf-page-header__meta { display: flex; flex-wrap: wrap; gap: .45rem; margin-top: .55rem; }
    .cf-page-header__actions { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; }
    .cf-context-chip { display: inline-flex; align-items: center; border: 1px solid var(--cf-border); border-radius: 999px; padding: .25rem .6rem; background: #f8fafc; color: #455a64; font-size: .78rem; font-weight: 600; }
    .cf-filter-bar { margin-bottom: 1rem; padding: .9rem 1rem .2rem; border: 1px solid var(--cf-border); border-radius: .55rem; background: #f8fafc; }
    .cf-filter-bar .form-group, .cf-filter-bar .mb-2 { margin-bottom: .7rem !important; }
    .cf-summary-card { height: 100%; border: 1px solid var(--cf-border); border-radius: .55rem; padding: .8rem .9rem; background: #fff; }
    .cf-summary-card__label { display: block; color: var(--cf-muted); font-size: .75rem; font-weight: 600; }
    .cf-summary-card__value { display: block; margin-top: .2rem; color: #243746; font-size: 1rem; font-weight: 700; }
    .cf-primary-action, .central-finance-page .btn-theme { background: var(--cf-navy); border-color: var(--cf-navy); color: #fff; }
    .cf-primary-action:hover, .central-finance-page .btn-theme:hover { background: #174c6d; border-color: #174c6d; color: #fff; }
    .central-finance-page .btn-outline-primary { color: var(--cf-navy); border-color: var(--cf-navy); }
    .central-finance-page .btn-outline-primary:hover { background: var(--cf-navy); border-color: var(--cf-navy); }
    .cf-data-table { table-layout: fixed; width: 100%; }
    .cf-data-table th { color: #52616b; font-size: .76rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
    .cf-data-table td { vertical-align: middle; }
    .cf-primary-line { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; color: #263b4a; font-weight: 600; }
    .cf-secondary-line { display: block; overflow: hidden; color: var(--cf-muted); font-size: .78rem; text-overflow: ellipsis; white-space: nowrap; }
    .cf-empty-state { padding: 2rem 1rem; color: var(--cf-muted); text-align: center; }
    .cf-status-badge { border-radius: 999px; font-size: .72rem; font-weight: 700; padding: .32rem .55rem; }
    .central-finance-page .pagination { flex-wrap: wrap; gap: .2rem; }
    @media (max-width: 575.98px) {
        .cf-page-header { align-items: flex-start; padding: .85rem; }
        .cf-page-header__actions { width: 100%; }
        .cf-page-header__actions > * { flex: 1 1 auto; }
        .cf-filter-bar { padding: .8rem .8rem .1rem; }
        .central-finance-page .card-body { padding: .9rem; }
        .cf-mobile-card-table, .cf-mobile-card-table tbody, .cf-mobile-card-table tr, .cf-mobile-card-table td { display: block; width: 100%; }
        .cf-mobile-card-table thead { display: none; }
        .cf-mobile-card-table tr { margin-bottom: .75rem; padding: .75rem; border: 1px solid var(--cf-border); border-radius: .55rem; background: #fff; }
        .cf-mobile-card-table tr:last-child { margin-bottom: 0; }
        .cf-mobile-card-table td { min-height: 1.55rem; padding: .2rem 0; border: 0; text-align: right; }
        .cf-mobile-card-table td::before { float: left; color: var(--cf-muted); content: attr(data-label); font-size: .74rem; font-weight: 700; text-align: left; }
        .cf-mobile-card-table td[data-label=""]::before { content: none; }
        .cf-mobile-card-table td:first-child { padding-top: 0; text-align: left; }
        .cf-mobile-card-table td:first-child::before { content: none; }
        .cf-mobile-card-table td:last-child { padding-bottom: 0; text-align: left; }
        .cf-mobile-card-table td:last-child::before { content: none; }
        .cf-mobile-card-table .btn { width: 100%; margin-top: .3rem; }
        .cf-ledger-table { min-width: 760px; }
    }
</style>

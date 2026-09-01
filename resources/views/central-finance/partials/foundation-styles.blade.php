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
    .cf-account-card { height: 100%; padding: 1rem; border: 1px solid var(--cf-border); border-radius: .65rem; background: #fff; }
    .cf-account-card__balance { margin: .9rem 0; padding: .75rem; border-radius: .45rem; background: #f8fafc; }
    .cf-account-card__balance small { display: block; color: var(--cf-muted); font-size: .75rem; font-weight: 600; }
    .cf-account-card__balance strong { display: block; margin-top: .15rem; color: #243746; font-size: 1.05rem; }
    .cf-account-card__details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem .9rem; }
    .cf-account-card__details div:last-child { grid-column: 1 / -1; }
    .cf-account-card__details dt { margin: 0; color: var(--cf-muted); font-size: .72rem; font-weight: 700; }
    .cf-account-card__details dd { margin: .12rem 0 0; color: #314553; font-size: .83rem; overflow-wrap: anywhere; }
    .cf-break-anywhere { overflow-wrap: anywhere; word-break: break-word; }
    .cf-workspace-intro { margin-top: 1rem; background: #fbfdff; }
    .cf-workspace-toolbar { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .85rem; margin-bottom: 1rem; }
    .cf-workspace-toolbar__actions { display: flex; flex-wrap: wrap; gap: .45rem; }
    .cf-file-name { display: block; max-width: 20rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cf-technical-detail summary { color: var(--cf-navy); cursor: pointer; font-size: .8rem; font-weight: 600; }
    .cf-technical-detail pre { max-width: 30rem; max-height: 14rem; margin: .65rem 0 0; padding: .65rem; overflow: auto; border: 1px solid var(--cf-border); border-radius: .4rem; background: #f8fafc; color: #52616b; white-space: pre-wrap; }
    .cf-export-card { display: flex; flex-direction: column; min-height: 5.5rem; justify-content: space-between; padding: 1rem; border: 1px solid var(--cf-border); border-radius: .6rem; background: #fff; color: #263b4a; text-decoration: none; }
    .cf-export-card:hover { border-color: var(--cf-navy); color: var(--cf-navy); text-decoration: none; }
    .cf-export-card span { color: var(--cf-muted); font-size: .8rem; }
    .cf-danger-panel { border-color: #f2c2c7 !important; background: #fffafb; }
    .cf-danger-panel .card-title { color: #8c2735; }
    .cf-history-notice { border-left: 3px solid #e7b64a; padding: .7rem .85rem; background: #fffaf0; color: #6d5730; font-size: .85rem; }
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
        .cf-account-card { padding: .9rem; }
        .cf-account-card__details { grid-template-columns: 1fr; }
        .cf-account-card__details div:last-child { grid-column: auto; }
        .cf-account-statement-workspace .cf-mobile-card-table td { overflow-wrap: anywhere; }
        .cf-workspace-toolbar__actions { width: 100%; }
        .cf-workspace-toolbar__actions > * { flex: 1 1 auto; }
        .cf-file-name { max-width: 100%; white-space: normal; overflow-wrap: anywhere; }
        .cf-technical-detail pre { max-width: 100%; }
        .cf-export-card { min-height: 4.75rem; }
    }
</style>

<style>
    .fee-item-card {
        position: relative;
        margin: 0 0 1rem;
        padding: 1rem 3.75rem 1rem 1rem;
        border: 1px solid #dce5ed;
        border-radius: .65rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(20, 40, 70, .04);
    }
    .fee-item-card .form-group { margin-bottom: .75rem; }
    .fee-item-card .fee-item-primary { align-items: end; }
    .fee-item-card .fee-card-actions {
        position: absolute;
        top: .8rem;
        right: .8rem;
    }
    .fee-item-card .fee-card-actions .btn { min-width: 2.4rem; min-height: 2.4rem; }
    .fee-item-card .fee-quantity-control {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        min-height: 3.25rem;
        padding: .55rem .75rem;
        border: 1px solid #dce5ed;
        border-radius: .45rem;
        background: #f8fbfd;
    }
    .fee-item-card .fee-quantity-control label { margin: 0; font-weight: 600; }
    .fee-item-card .fee-quantity-control small { display: block; margin-top: .15rem; }
    .fee-item-card .fee-quantity-control .form-switch { margin: 0; padding-left: 2.5rem; }
    .fee-item-card.is-mmk .fee-exchange-detail,
    .fee-item-card.is-mmk .fee-mmk-detail { display: none; }
    .fee-item-card .finance-category-help { margin-top: .35rem; }
    @media (max-width: 575.98px) {
        .fee-item-card { padding: .85rem .85rem 3.8rem; }
        .fee-item-card .fee-card-actions { top: auto; right: .85rem; bottom: .85rem; }
        .fee-item-card .fee-quantity-control { align-items: flex-start; }
    }
</style>

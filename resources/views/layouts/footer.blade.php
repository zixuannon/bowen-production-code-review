<footer class="footer app-footer">
    <div class="app-footer__inner">
        <a class="app-footer__brand" href="{{ url('/') }}" aria-label="BOWEN SCHOOL">
            <span class="app-footer__mark" aria-hidden="true">B</span>
            <span><strong>BOWEN SCHOOL</strong><small>eSchool</small></span>
        </a>
        <span class="text-muted app-footer__copyright">{{ __('Copyright') }} © <?= date('Y') ?> {{ config('app.name') }}. {{ __('All rights reserved') }}.</span>
    </div>
</footer>

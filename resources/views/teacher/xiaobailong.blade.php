@extends('layouts.master')

@section('title')
    晓白龍 AI 助教
@endsection

@section('content')
    <div class="content-wrapper xbl-wrapper">
        <div class="page-header xbl-page-header">
            <h3 class="page-title">
                <span class="page-title-icon bg-theme text-white mr-2"><i class="fa fa-magic"></i></span>
                晓白龍 AI 助教
            </h3>
            <span class="xbl-secure"><i class="fa fa-lock"></i> 已使用教师身份安全登录</span>
        </div>
        <div class="xbl-frame-card">
            <div class="xbl-loading" id="xbl-loading"><i class="fa fa-spinner fa-spin"></i> 正在打开晓白龍工作台…</div>
            <iframe
                id="xbl-frame"
                title="晓白龍 AI 助教"
                src="{{ route('xiaobailong.launch') }}"
                referrerpolicy="strict-origin"
                allow="clipboard-read; clipboard-write"
            ></iframe>
        </div>
    </div>

    <style>
        .xbl-wrapper { padding-bottom: 1.5rem; }
        .xbl-page-header { align-items: center; margin-bottom: 1rem; }
        .xbl-secure { color: #198754; font-size: .8rem; }
        .xbl-frame-card { position: relative; min-height: 760px; overflow: hidden; border: 1px solid #e8e8e8; border-radius: 14px; background: #fff; box-shadow: 0 8px 30px rgba(35, 38, 45, .06); }
        .xbl-frame-card iframe { display: block; width: 100%; height: calc(100vh - 175px); min-height: 760px; border: 0; background: #f7f6f3; }
        .xbl-loading { position: absolute; inset: 0; z-index: 2; display: flex; align-items: center; justify-content: center; gap: .6rem; color: #666; background: #f7f6f3; }
        @media (max-width: 768px) { .xbl-frame-card, .xbl-frame-card iframe { min-height: 680px; } .xbl-frame-card iframe { height: calc(100vh - 145px); } .xbl-secure { display: none; } }
    </style>
    <script>
        document.getElementById('xbl-frame').addEventListener('load', function () {
            document.getElementById('xbl-loading').style.display = 'none';
        });
    </script>
@endsection

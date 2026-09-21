@php
    // 静态资源指纹：后台资源一旦变化，URL 随之变化，避免浏览器 / CDN 继续使用旧 JS/CSS
    // （umi.js 是编译产物，菜单/路由补丁后必须让客户端拿到新文件）
    $assetFiles = array_merge(
        glob(public_path('assets/admin/*.js')) ?: [],
        glob(public_path('assets/admin/*.css')) ?: []
    );
    $assetVer = $version . '-' . ($assetFiles ? max(array_map('filemtime', $assetFiles)) : time());
@endphp
<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$assetVer}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$assetVer}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$assetVer}}">
    <link rel="stylesheet" href="/assets/admin/subaccount-admin-page.css?v={{$assetVer}}">
    <link rel="stylesheet" href="/assets/admin/checkin-promotion-admin.css?v={{$assetVer}}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$assetVer}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$assetVer}}"></script>
<script src="/assets/admin/components.async.js?v={{$assetVer}}"></script>
<script src="/assets/admin/umi.js?v={{$assetVer}}"></script>
<script src="/assets/admin/subaccount-admin-page.js?v={{$assetVer}}"></script>
<script src="/assets/admin/checkin-promotion-admin-page.js?v={{$assetVer}}"></script>
</body>

</html>

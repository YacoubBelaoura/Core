@extends('laravel-enso/core::layouts.app')

@section('pageTitle', __("Permissions"))

@section('content')

<section class="content-header">
    <a class="btn btn-primary" href="/system/permissions/create">
        {{ __("Create Permission") }}
    </a>
    <a class="btn btn-primary" href="/system/resourcePermissions/create">
        {{ __("Create Resource") }}
    </a>
    <a class="btn btn-primary" href="/system/permissionsGroups/create">
        {{ __("Create Group") }}
    </a>
    @include('laravel-enso/core::partials.breadcrumbs')
</section>
<section class="content">
    <div class="row" v-cloak>
        <div class="col-md-12">
            <data-table source="/system/permissions">
                <span slot="data-table-title">{{ __("Permissions") }}</span>
                @include('laravel-enso/core::partials.modal')
            </data-table>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript" src="{{ asset('/js/vendor/laravel-enso/pages/index.js') }}"></script>
@endpush
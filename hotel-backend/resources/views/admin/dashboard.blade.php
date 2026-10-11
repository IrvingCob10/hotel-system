@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1>Dashboard</h1>
            <p class="text-muted mb-0">
                Panel de administración de Hotel Management
            </p>
        </div>
    </div>
@stop

@section('content')

    {{-- Bienvenida --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="mb-1">
                        Bienvenido, {{ auth()->user()->name }}
                    </h4>

                    <p class="text-muted mb-0">
                        Desde este panel puedes administrar la plataforma Hotel Management.
                    </p>
                </div>
            </div>
        </div>
    </div>


    {{-- Estadísticas --}}
    <div class="row">

        {{-- Clientes --}}
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ $stats['customers'] }}</h3>
                    <p>Clientes</p>
                </div>

                <div class="icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>


        {{-- Hoteles --}}
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ $stats['hotels'] }}</h3>
                    <p>Hoteles</p>
                </div>

                <div class="icon">
                    <i class="fas fa-hotel"></i>
                </div>
            </div>
        </div>


        {{-- Usuarios --}}
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3>{{ $stats['users'] }}</h3>
                    <p>Usuarios</p>
                </div>

                <div class="icon">
                    <i class="fas fa-user"></i>
                </div>
            </div>
        </div>


        {{-- Licencias --}}
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ $stats['licenses'] }}</h3>
                    <p>Licencias</p>
                </div>

                <div class="icon">
                    <i class="fas fa-key"></i>
                </div>
            </div>
        </div>


        {{-- Planes --}}
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="small-box bg-danger">
                <div class="inner">
                    <h3>{{ $stats['plans'] }}</h3>
                    <p>Planes</p>
                </div>

                <div class="icon">
                    <i class="fas fa-box"></i>
                </div>
            </div>
        </div>


        {{-- Módulos --}}
        <div class="col-lg-4 col-md-6 col-sm-12">
            <div class="small-box bg-secondary">
                <div class="inner">
                    <h3>{{ $stats['modules'] }}</h3>
                    <p>Módulos</p>
                </div>

                <div class="icon">
                    <i class="fas fa-puzzle-piece"></i>
                </div>
            </div>
        </div>

    </div>


    {{-- Cerrar sesión --}}
    <div class="row mt-4">
        <div class="col-12">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        Sesión
                    </h3>
                </div>

                <div class="card-body">

                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf

                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-sign-out-alt mr-1"></i>
                            Cerrar sesión
                        </button>
                    </form>

                </div>
            </div>

        </div>
    </div>

@stop
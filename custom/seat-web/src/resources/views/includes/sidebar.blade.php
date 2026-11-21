<aside class="main-sidebar elevation-4 sidebar-dark-primary">

  <!-- Logo -->
  <a href="{{ route('seatcore::home') }}" class="brand-link">
    <img class="brand-image img-circle elevation-3" src="{{ asset('web/img/logo.png') }}" alt="SeAT" />
    <span class="brand-text font-weight-light">S<b>e</b>AT</span>
  </a>

  <!-- sidebar: style can be found in sidebar.less -->
  <div class="sidebar">

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu">

        <li class="nav-item">
          <a href="{{ route('seatcore::fittings.index') }}" class="nav-link {{ Request::segment(1) === 'fittings' && Request::segment(2) === null ? 'active' : null }}">
            <i class="nav-icon fas fa-ship"></i>
            <p>Fittings</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="{{ route('seatcore::reactions.index') }}" class="nav-link {{ Request::segment(1) === 'reactions' ? 'active' : null }}">
            <i class="nav-icon fas fa-flask"></i>
            <p>Reactions</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="{{ route('seatcore::planetary.index') }}" class="nav-link {{ Request::segment(1) === 'pi' ? 'active' : null }}">
            <i class="nav-icon fas fa-globe-americas"></i>
            <p>Planetary Industry</p>
          </a>
        </li>
        @can('global.superuser')
        <li class="nav-item">
          <a href="{{ route('seatcore::fittings.manage') }}" class="nav-link {{ Request::segment(1) === 'fittings' && Request::segment(2) === 'manage' ? 'active' : null }}">
            <i class="nav-icon fas fa-tools"></i>
            <p>Manage Fittings</p>
          </a>
        </li>
        @endcan

        @php
          $marketUser = auth()->user();
          $marketRoleAccess = $marketUser && method_exists($marketUser, 'roles') && $marketUser->roles()->where('title', 'Marketeers')->exists();
        @endphp
        @if($marketUser && ($marketUser->can('global.superuser') || $marketRoleAccess))
        <li class="nav-item"><a href="{{ route('seatcore::market.index') }}" class="nav-link {{ Request::segment(1)==='market'?'active':null }}"><i class="nav-icon fas fa-chart-line"></i><p>Market</p></a></li>
        @endif

        @php
          $financeUser = auth()->user();
          $financeCharacterAccess = $financeUser && $financeUser->characters->contains(function ($character) {
            return strcasecmp((string) ($character->name ?? $character->character_name ?? ''), 'Turaneau') === 0;
          });
        @endphp
        @if($financeCharacterAccess)
        <li class="nav-item"><a href="{{ route('seatcore::potchven-finances.index') }}" class="nav-link {{ Request::segment(1)==='potchven-finances'?'active':null }}"><svg class="nav-icon" viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false" style="vertical-align:-0.12em"><path d="M12 2.4 21.1 19H2.9L12 2.4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 2.4V12M12 12 5.1 16.2M12 12l6.9 4.2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg><p>Potchven Finances</p></a></li>
        @endif

        <li class="nav-item">
          <a href="{{ route('seatcore::mining.index') }}" class="nav-link {{ Request::segment(1) === 'mining' ? 'active' : null }}">
            <i class="nav-icon fas fa-gem"></i>
            <p>Mining Ledger</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="{{ route('seatcore::cookbook.index') }}" class="nav-link {{ Request::segment(1) === 'cookbook' ? 'active' : null }}">
            <i class="nav-icon fas fa-industry"></i>
            <p>Triglavian Cookbook</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="{{ route('seatcore::divine-reign.index') }}" class="nav-link {{ Request::segment(1) === 'divine-reign' ? 'active' : null }}">
            <i class="nav-icon fas fa-skull-crossbones"></i>
            <p>Divine Reign</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="{{ route('seatcore::exploration.index') }}" class="nav-link {{ Request::segment(1) === 'exploration' ? 'active' : null }}">
            <i class="nav-icon fas fa-compass"></i>
            <p>Exploration</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="{{ route('seatcore::production.bustard') }}" class="nav-link {{ Request::is('production/bustard') ? 'active' : null }}">
            <i class="nav-icon fas fa-industry"></i>
            <p>Bustard</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="{{ route('seatcore::production.crane') }}" class="nav-link {{ Request::is('production/crane') ? 'active' : null }}">
            <i class="nav-icon fas fa-industry"></i>
            <p>Crane</p>
          </a>
        </li>

        @foreach($menu as $entry)

          {{-- determine if we should pop a treeview --}}
          @if(isset($entry['entries']))

            <li class="nav-item has-treeview {{ Request::segment(1) === $entry['route_segment'] ? 'menu-is-opening menu-open' : null }}">
              <a href="#" class="nav-link {{ Request::segment(1) === $entry['route_segment'] ? 'active' : null }}">
                <i class="nav-icon {{ $entry['icon'] }}"></i>

                @if (array_key_exists('label', $entry))

                  @if(array_key_exists('plural', $entry))
                    <p>
                      {{ trans_choice($entry['label'], $entry['plural'] ? 0 : 1) }}
                      <i class="right fas fa-angle-left"></i>
                    </p>
                  @else
                    <p>
                      {{ trans($entry['label']) }}
                      <i class="right fas fa-angle-left"></i>
                    </p>
                  @endif

                @else

                  <p>
                    {{ $entry['name'] }}
                    <i class="right fas fa-angle-left"></i>
                  </p>

                @endif

              </a>
              <ul class="nav nav-treeview">

                @foreach($entry['entries'] as $item)

                  {{-- check if a permisison is required an if its given --}}
                  @if(array_key_exists('permission', $item))

                    {{-- permision is required. check it --}}
                    @canany(is_array($item['permission']) ? $item['permission'] : [$item['permission']])

                      <li class="nav-item">
                        <a href="{{ isset($item['route']) ? route($item['route']) : '#' }}" class="nav-link {{ isset($item['route']) ? (Request::url() === route($item['route']) ? 'active' : null) : null }}">

                          @if (array_key_exists('label', $item))

                            <i class="nav-icon {{ $item['icon'] ?? 'fa-circle-o' }}"></i>
                            @if(array_key_exists('plural', $item))
                              <p>{{ trans_choice($item['label'], $item['plural'] ? 0 : 1) }}</p>
                            @else
                              <p>{{ trans($item['label']) }}</p>
                            @endif

                          @else

                            <i class="nav-icon {{ $item['icon'] ?? 'fa-circle-o' }}"></i> {{ $item['name'] }}

                          @endif

                        </a>

                        @if(array_key_exists('entries', $item))
                          <ul class="nav nav-treeview">
                            @foreach($item['entries'] as $subitem)
                              <li class="nav-item">
                                <a href="{{ isset($subitem['route']) ? route($subitem['route']) : '#' }}" class="nav-link {{ isset($subitem['route']) ? (Request::url() === route($subitem['route']) ? 'active' : null) : null }}">
                                  @if (array_key_exists('label', $subitem))
                                    <i class="nav-icon {{ $subitem['icon'] ?? 'fa-circle-o' }}"></i>
                                    @if(array_key_exists('plural', $subitem))
                                      <p>{{ trans_choice($subitem['label'], $subitem['plural'] ? 0 : 1) }}</p>
                                    @else
                                      <p>{{ trans($subitem['label']) }}</p>
                                    @endif
                                  @else
                                    <i class="nav-icon {{ $subitem['icon'] ?? 'fa-circle-o' }}"></i>
                                    <p>{{ $subitem['name'] }}</p>
                                  @endif
                                </a>
                              </li>
                            @endforeach
                          </ul>
                        @endif

                      </li>

                    @endcanany

                    {{-- TODO: Get rid of this copy pasta by using a partial or something. --}}
                  @else

                    <li class="nav-item">
                      <a href="{{ isset($item['route']) ? route($item['route']) : '#' }}" class="nav-link {{ isset($item['route']) ? (Request::url() === route($item['route']) ? 'active' : null) : null }}">
                        @if (array_key_exists('label', $item))

                          <i class="nav-icon {{ $item['icon'] ?? 'fa-circle-o' }}"></i>

                          @if(array_key_exists('plural', $item))
                            <p>{{ trans_choice($item['label'], $item['plural'] ? 0 : 1) }}</p>
                          @else
                            <p>{{ trans($item['label']) }}</p>
                          @endif

                        @else

                          <i class="nav-icon {{ $item['icon'] ?? 'fa-circle-o' }}"></i>
                          <p>{{ $item['name'] }}</p>

                        @endif

                      </a>

                      @if(array_key_exists('entries', $item))
                        <ul class="nav nav-treeview">
                          @foreach($item['entries'] as $subitem)
                            <li class="nav-item">
                              <a href="{{ isset($subitem['route']) ? route($subitem['route']) : '#' }}" class="nav-link {{ isset($subitem['route']) ? (Request::url() === route($subitem['route']) ? 'active' : null) : null }}">
                                @if (array_key_exists('label', $subitem))
                                  <i class="nav-icon {{ $subitem['icon'] ?? 'fa-circle-o' }}"></i>
                                  @if(array_key_exists('plural', $subitem))
                                    <p>{{ trans_choice($subitem['label'], $subitem['plural'] ? 0 : 1) }}</p>
                                  @else
                                    <p>{{ trans($subitem['label']) }}</p>
                                  @endif
                                @else
                                  <i class="nav-icon {{ $subitem['icon'] ?? 'fa-circle-o' }}"></i>
                                  <p>{{ $subitem['name'] }}</p>
                                @endif
                              </a>
                            </li>
                          @endforeach
                        </ul>
                      @endif

                    </li>

                  @endif

                @endforeach
              </ul>
            </li>

            {{-- render custom menu links --}}
          @elseif($entry['route_segment'] === 'custom')

          <li class="nav-item">
              <a href="{{ $entry['route'] }}" class="nav-link" @if ($entry['new_tab'] == true) target="_blank" @endif>
                <i class="nav-icon {{ ($entry['icon'] != '') ? $entry['icon'] : 'fas fa-link' }}"></i>
                <p>{{ $entry['name'] }}</p>
              </a>
            </li>

            {{-- no entries, so this looks like a single menu --}}
          @else

            <li class="nav-item">
              <a href="{{ isset($entry['route']) ? route($entry['route']) : '#' }}" class="nav-link {{ Request::segment(1) === $entry['route_segment'] ? 'active' : null }}">

                @if (array_key_exists('label', $entry))
                  <i class="nav-icon {{ $entry['icon'] }}"></i>
                  @if(array_key_exists('plural', $entry))
                    <p>{{ trans_choice($entry['label'], $entry['plural'] ? 0 : 1) }}</p>
                  @else
                    <p>{{ trans($entry['label']) }}</p>
                  @endif
                @else
                  <i class="nav-icon {{ $entry['icon'] }}"></i>
                  <p>{{ $entry['name'] }}</p>
                @endif

              </a>
            </li>

          @endif

        @endforeach

      </ul>
    </nav>
    <!-- /.sidebar-menu -->

  </div>
  <!-- /.sidebar -->
</aside>

#!/usr/bin/env python3
"""Idempotently add RigAuth route registrations and SeAT sidebar links."""
from pathlib import Path
import sys

if len(sys.argv) != 3:
    raise SystemExit("Usage: apply-exploration-navigation.py ROUTES_PHP SIDEBAR_BLADE")

routes_path, sidebar_path = map(Path, sys.argv[1:])
routes = routes_path.read_text()
sidebar = sidebar_path.read_text()

home_anchor = "        include __DIR__ . '/Routes/Home.php';\n"
if routes.count(home_anchor) != 1:
    raise SystemExit("Could not find the unique SeAT Home route include; no route changes made.")

home_additions = []
if "Routes/TriglavianSso.php" not in routes:
    home_additions.extend([
        "        // Signed SSO bridge for the Triglavian Market app.\n",
        "        include __DIR__ . '/Routes/TriglavianSso.php';\n",
    ])
if "seatcore::reactions.index" not in routes:
    home_additions.extend([
        "        // Triglavian Consortium reaction planner (read-only inventory lookup).\n",
        "        Route::get('/reactions', 'ReactionsController@index')\n",
        "            ->name('seatcore::reactions.index');\n",
    ])
if home_additions:
    routes = routes.replace(home_anchor, home_anchor + "\n" + "".join(home_additions) + "\n", 1)

admin_anchor = "        // Impersonation Helper Group. This one is Separate purely\n"
admin_routes = """        // Triglavian administration routes.
        Route::group([
            'namespace' => 'Configuration',
            'prefix' => 'configuration',
        ], function () {
            include __DIR__ . '/Routes/Configuration/Triglavian.php';
        });

"""
if "Routes/Configuration/Triglavian.php" not in routes:
    if routes.count(admin_anchor) != 1:
        raise SystemExit("Could not find the unique SeAT configuration route anchor; no route changes made.")
    routes = routes.replace(admin_anchor, admin_routes + admin_anchor, 1)

menu_anchor = "        @foreach($menu as $entry)"
if sidebar.count(menu_anchor) != 1:
    raise SystemExit("Could not find the unique SeAT sidebar menu anchor; no sidebar changes made.")

links = [
    ("seatcore::reactions.index", """        <li class="nav-item">
          <a href="{{ route('seatcore::reactions.index') }}" class="nav-link {{ Request::segment(1) === 'reactions' ? 'active' : null }}">
            <i class="nav-icon fas fa-flask"></i>
            <p>Reactions</p>
          </a>
        </li>

"""),
    ("seatcore::exploration.index", """        <li class="nav-item">
          <a href="{{ route('seatcore::exploration.index') }}" class="nav-link {{ Request::segment(1) === 'exploration' ? 'active' : null }}">
            <i class="nav-icon fas fa-compass"></i>
            <p>Exploration</p>
          </a>
        </li>

"""),
    ("seatcore::configuration.triglavian.index", """        @if(auth()->check() && (auth()->user()->can('global.superuser') || strtolower(auth()->user()->name ?? '') === 'turaneau'))
        <li class="nav-item">
          <a href="{{ route('seatcore::configuration.triglavian.index') }}" class="nav-link {{ Request::segment(2) === 'triglavian' ? 'active' : null }}">
            <i class="nav-icon fas fa-cogs"></i>
            <p>Admin Setup</p>
          </a>
        </li>
        @endif

"""),
]
for route_name, link in links:
    if route_name not in sidebar:
        sidebar = sidebar.replace(menu_anchor, link + menu_anchor, 1)

routes_path.write_text(routes)
sidebar_path.write_text(sidebar)
print("RigAuth routes and navigation links are present.")

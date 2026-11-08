#!/usr/bin/env python3
"""Idempotently add the Exploration route include and SeAT sidebar link."""
from pathlib import Path
import sys

if len(sys.argv) != 3:
    raise SystemExit("Usage: apply-exploration-navigation.py ROUTES_PHP SIDEBAR_BLADE")

routes_path, sidebar_path = map(Path, sys.argv[1:])
routes = routes_path.read_text()
sidebar = sidebar_path.read_text()

route_include = "        // Triglavian Consortium regional exploration maps.\n        include __DIR__ . '/Routes/Exploration.php';\n"
route_anchor = "        include __DIR__ . '/Routes/Home.php';\n"
if "Routes/Exploration.php" not in routes:
    if routes.count(route_anchor) != 1:
        raise SystemExit("Could not find the unique SeAT Home route include; no route changes made.")
    routes = routes.replace(route_anchor, route_anchor + "\n" + route_include, 1)

sidebar_link = """        <li class="nav-item">
          <a href="{{ route('seatcore::exploration.index') }}" class="nav-link {{ Request::segment(1) === 'exploration' ? 'active' : null }}">
            <i class="nav-icon fas fa-compass"></i>
            <p>Exploration</p>
          </a>
        </li>

"""
sidebar_anchor = "        @foreach($menu as $entry)"
if "seatcore::exploration.index" not in sidebar:
    if sidebar.count(sidebar_anchor) != 1:
        raise SystemExit("Could not find the unique SeAT sidebar menu anchor; no sidebar changes made.")
    sidebar = sidebar.replace(sidebar_anchor, sidebar_link + sidebar_anchor, 1)

routes_path.write_text(routes)
sidebar_path.write_text(sidebar)
print("Exploration route and sidebar navigation are present.")

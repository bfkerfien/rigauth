# Fittings display preview

This directory is a static interface preview for the Fittings section proposed for `rigauth`. It reuses the Market interface palette and layout patterns while collecting Fits, Shopping List, and Deployment Shopping List into one workspace.

Open `index.html` in a browser to view the preview. The displayed ship, item, and inventory values are sample data. EVE SSO, Market APIs, hangar queries, persistence, and deployment wiring are intentionally not connected yet.

## Layout

- The left navigation opens and collapses the Fittings section.
- The workspace has Fits, Shopping List, and Deployment Shopping List tabs.
- Each shopping list has Modules, Hulls & Rigs, and Consumables subtabs.
- The deployment preview counts one ship for every assigned fit.

## Files

- `index.html` — page structure and sample display rows
- `styles.css` — responsive dark Market-style interface
- `app.js` — tab, navigation, filter, and copy interactions


# SeAT SSO bridge for Triglavian Market

This repository adds a small signed assertion endpoint to the SeAT deployment at `https://seat.triglavianconsortium.com`. It is an identity bridge for the fitting-management UI on `https://market.triglavianconsortium.com`; it does not replace EVE SSO used by the market collector to access ESI structure-market data.

## Deployment configuration

1. Generate one high-entropy shared secret, for example:

   ```sh
   openssl rand -hex 32
   ```

2. Add the generated value to the SeAT server's private `.env`:

   ```dotenv
   TRIGLAVIAN_MARKET_SSO_SECRET=the_generated_secret
   TRIGLAVIAN_MARKET_SSO_CALLBACK=https://market.triglavianconsortium.com/auth/seat/callback
   ```

3. Add the same secret to `market/collector/.env` on the market server as `SEAT_SSO_SHARED_SECRET`. Set a separate random `SESSION_SECRET` for the collector. Do not use the shared SSO secret as the session secret.
4. Rebuild the SeAT source with `restore-seat-source.sh`, then build/restart SeAT using the normal deployment procedure. Rebuild/restart the market collector after setting its environment.
5. Open `https://market.triglavianconsortium.com/doctrine-manager` and choose **Sign in with SeAT**.

The bridge only accepts the exact configured callback URL, only issues assertions for a logged-in SeAT user with `global.superuser`, sets a 60-second expiry, and includes the caller-provided one-time state. The market collector verifies the signature and consumes the state before establishing a secure session.

## Security notes

- Keep both server `.env` files private; never commit credentials.
- The market collector's `EVE_CLIENT_ID`, `EVE_CLIENT_SECRET`, `EVE_REDIRECT_URI`, and `ADMIN_TOKEN` remain solely for authorizing the EVE character used to collect structure-market data.
- The old Reactions/PI fit-manager verification endpoint is no longer used for editing fits or doctrines.
- If the shared secret changes, update both services and restart both.

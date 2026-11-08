#!/usr/bin/env python3
"""Add SeAT's existing main-character change endpoint to the profile page."""
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit("Usage: apply-main-character-selector.py PROFILE_BLADE")

path = Path(sys.argv[1])
content = path.read_text()
marker = "  @include('web::profile.modals.email.email')"
block = """  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Main Character</h3>
    </div>
    <div class="card-body">
      <p class="text-muted">Choose which of your linked EVE characters represents this SeAT account. This changes the account's main character; it does not unlink any characters or change permissions.</p>
      <form method="post" action="{{ route('seatcore::profile.change-character') }}" class="form-inline">
        {{ csrf_field() }}
        <div class="form-group flex-grow-1 mr-2 mb-2">
          <label class="sr-only" for="seat-main-character">Main character</label>
          <select class="form-control w-100" id="seat-main-character" name="character_id" required>
            @foreach($characters as $character)
              <option value="{{ $character->character_id }}" @if((int) $user->main_character_id === (int) $character->character_id) selected @endif>{{ $character->name }}</option>
            @endforeach
          </select>
        </div>
        <button type="submit" class="btn btn-primary mb-2">Set Main Character</button>
      </form>
    </div>
  </div>

"""
if "id=\"seat-main-character\"" not in content:
    if content.count(marker) != 1:
        raise SystemExit("Could not find unique profile modal anchor; no changes made.")
    content = content.replace(marker, block + marker, 1)
path.write_text(content)
print("Main-character selector is present in the SeAT profile.")

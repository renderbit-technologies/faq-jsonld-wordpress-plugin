# A URL association means the URL, not the post that was there when it was saved

Indexing used to resolve every saved URL to a post ID and store an extra `post` mapping "for faster lookup". That made a URL association quietly follow the post it pointed to when saved: renamed posts kept the FAQ, and so did a different post that later took over the URL. It also meant matching rules were defined twice, once for URLs and once for the resolved ID. We dropped the extra row: a URL association covers whichever post's permalink equals that URL now. Internal URLs are stored relative to the site root, so scheme, `www.` and domain changes don't break them.

## Consequences

- Do not add a `post` mapping for URL associations again; the lookup is already a single indexed query.
- Changing the permalink structure, category or tag base, or the home URL changes every post's URL, so those changes purge all cached output.
- The data migration to version 3 rewrote URL associations that only matched through the old `post` row, and recorded every rewrite in a notice for editors.
- Saving a URL that is not the permalink of a post on this site shows a warning, because it can never match.

# Renderbit FAQ Structured Data

Editors write FAQs once and associate them with the posts and pages they apply to. The plugin prints those FAQs as schema.org `FAQPage` structured data on each matching singular post.

## Language

**FAQ**:
One question and its answer, kept as its own item and reusable across many posts.
_Avoid_: FAQ entry, question

**Association**:
An editor's statement of where a FAQ applies: specific URLs, specific posts, whole post types, taxonomy terms, or the whole site.
_Avoid_: rule, assignment, target

**URL association**:
An association with a URL itself. It covers whichever post is at that URL now, not the post that was there when the association was saved.

**Mapping**:
A single indexed fact that one FAQ applies to one thing: a post, a post type, a term, a URL, or everything (global).
_Avoid_: index row, link, relation

**Coverage**:
The set of posts a mapping applies to.
_Avoid_: scope, reach, matches

**Lookup candidates**:
The mappings that could match a given post: its ID, its post type, its terms, its URL, and global.
_Avoid_: lookup keys, match values

**Coverable post**:
A published post. Only coverable posts have cached output, so invalidation only ever needs the coverable part of a mapping's coverage.

**Cached output**:
The FAQ structured data last rendered for a post, or the record that it has none.
_Avoid_: transient (that is just the storage)

**Invalidation**:
Discarding cached output that a change may have made wrong.
_Avoid_: purge (that means discarding all cached output at once), flush

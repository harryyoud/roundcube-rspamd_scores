# rspamd scores
Displays rspamd score breakdown in roundcube

## Creating score mapping
```
$ rspamadm configdump -dj | jq '[.symbols | to_entries[] | .value = .value.description] | sort | from_entries'
```

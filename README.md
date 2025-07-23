# rspamd scores
Displays rspamd score breakdown in roundcube

## Creating score mapping
```
$ rspamadm configdump --symbol-details --json | jq --sort-keys '.symbols | with_entries({key: .key, value: .value.description})'
```

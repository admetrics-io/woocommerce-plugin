## Development

```
# Starting your local WP environment 
make dev

# Stopping ayour local WP environment
make down

# Cleanup your local system (removing all generated/downloaded files and Docker containers)
make clean
```

### Publishing a new release

1. Define new version (major, minor, patch).
2. Update version references by running `make release VERSION=x.x.x`
3. Commit and push to GitHub
4. "Draft a new release" on https://github.com/admetrics-io/woocommerce-plugin/releases and use the latest version as
   new tag.

# Team++ media API client

> A client for the [Team++ media API](http://media.plusp.lu) used in all project websites.

## What it does

The client is totally Kirby-specific - it accesses files in the `content/episodes` directory of a project website and analyzes them to:

- Get the name of the current/next episode
- Get the current status of the next episode ("Live", "ReLive", "Soon")
- Serve data from the API for usage in feeds etc.
# REST API

Icinga Notifications Web provides a REST API that allows you to manage notification-related resources programmatically.

With this API, you can:
- Manage **contacts** and **contact groups**
- Read available **notification channels**

This API enables easy integration with external tools, automation workflows, and configuration management systems.

## API Versioning

The API follows a **versioned** structure to ensure backward compatibility and predictable upgrades.

The current and first stable version is: /icingaweb2/notifications/api/v1

Future versions will be accessible under corresponding paths (for example, `/api/v2`), allowing you to migrate at your own pace.

## API Description

The complete API reference for version `v1` is available in [`api/v1.md`](api/v1.md).

It contains an OpenAPI v3.1 description with detailed information about all endpoints, including:
- Request and response schemas
- Example payloads
- Authentication requirements
- Error handling

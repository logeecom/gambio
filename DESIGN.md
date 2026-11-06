# DESIGN.md — Mollie module for Gambio

Living architecture record. Describes the system **as it is** on branch `4.5-5.x` (GX 4.5–5.0). Branch `4.1-4.4` (GX 4.1–4.4) has the same architecture, components, flows and patterns; its differences are listed in §9 and in `CLAUDE.md` § Supported branches. Any architectural change updates
this file (including diagrams) in the same pass.

## 1. Purpose

This module lets a Gambio GX 4.5.x–5.0.x shop take payments through Mollie. It exposes 23 Mollie payment methods as Gambio payment modules (`includes/modules/payment/mollie_*.php`), plus one optional surcharge order-total module (`ot_mollie`). A Module Center module handles API-key connection, order-status mapping and notifications. Order-detail extenders add a Mollie dashboard with refund, capture and payment-link actions. Payment state comes back through a shop webhook and a checkout redirect controller. Business logic (API proxy, payment/order services, webhook dispatch, ORM, notifications, surcharge, version check) lives in the vendored `mollie/integration-core` library. This module is the Gambio wrapper that implements the core's integration interfaces and maps Gambio orders to Mollie DTOs.

## 2. Architecture overview

The design has four layers, and dependencies point downward:

1. **Gambio entry points.** Payment and order-total modules (`includes/modules/`), GXModules HTTP controllers (Shop and Admin), and Gambio overload extenders. These are thin adapters invoked by Gambio. The module deliberately keeps the classic Gambio module structure (`includes/modules/payment` + `GXModules`) so that one codebase supports multiple Gambio versions.
2. **Wrapper components** (`Mollie\Gambio\*` under `GXModules/Mollie/Mollie/Components`). These are the Bootstrap, processors, mappers, the Gambio implementations of core interfaces, order reset, providers and repositories.
3. **Shared core** (`vendor/mollie/integration-core`). It contains `ServiceRegister`, `Proxy`, `PaymentService`, `OrderService`, `WebHookTransformer`, `EventBus`, `NotificationHub`, the ORM and `Configuration`.
4. **External systems and Gambio persistence.** These are the Mollie API, Mollie.js, the GitHub raw version file, and the Gambio DB (`gx_configurations`, `mollie_entity`, Gambio order/product tables).

The core calls back into the wrapper only through registered interfaces:
- `OrderTransitionService` (also implementing the optional `PendingOrderTransitionService`, core 1.3.12)
- `OrderLineTransitionService`
- `Configuration`
- `ShopLoggerAdapter`
- `RepositoryInterface`
- `PaymentTransactionDescriptionService`
- `VersionCheckService`
- `MaintenanceModeService`

All Mollie HTTP goes through the core's own `Proxy`/`BaseProxy` with `CurlHttpClient`. The `mollie/mollie-api-php` SDK is not used.

```mermaid
flowchart TD
    subgraph GX["Gambio entry points"]
        PM["Payment modules<br/>includes/modules/payment/mollie_*.php"]
        OT["ot_mollie<br/>includes/modules/order_total"]
        SC["Shop controllers<br/>MollieWebhook / MollieCheckoutRedirect / MolliePaymentLinkRedirect"]
        AC["Admin controllers<br/>Configuration, Connect, Refund, Capture, PaymentLink, Notification, Support, FileUpload"]
        MCM["MollieModuleCenterModule<br/>install / uninstall"]
        OVL["Overload extenders<br/>OrderExtender, OrderWriteService, AdminApplicationTop, OrderActions, ConfigurationBoxContentView"]
    end

    subgraph WR["Wrapper: Mollie\\Gambio (Components)"]
        BOOT["BootstrapComponent<br/>autoload.php"]
        PROC["APIProcessor<br/>ProcessorFactory / ProcessorRegister / PaymentProcessor"]
        MAP["Mappers<br/>OrderMapper, OrderTotalMapper, OrderStatusMapper"]
        SVC["Services\\Business<br/>ConfigurationService, PaymentMethodService, OrderTransitionService, ..."]
        RST["OrderReset<br/>OrderResetService, Restock, Reship, ReactivateArticle"]
        PRV["Providers<br/>MollieRedirectProvider, PaymentLinkProvider, CustomFields, Utility"]
        LCK["DatabaseLock<br/>MySQL named lock per order"]
        UPD["Update<br/>PaymentMethodUpdate, OrderStatusUpdate"]
        REPO["Entity\\Repository<br/>BaseRepository (mollie_entity), Gambio*Repository"]
    end

    subgraph CORE["Shared core: vendor/mollie/integration-core"]
        REG["ServiceRegister / RepositoryRegistry / EventBus"]
        BL["BusinessLogic services<br/>PaymentService, OrderService, OrderReferenceService, CheckoutLinkService, CustomerService, SurchargeService"]
        WH["WebHookTransformer + WebHook handlers"]
        PX["Proxy / VersionCheckProxy<br/>CurlHttpClient"]
    end

    subgraph EXT["External / persistence"]
        MAPI["Mollie API v2<br/>api.mollie.com"]
        GH["raw.githubusercontent.com<br/>composer.json version"]
        DB[("Gambio DB<br/>gx_configurations, mollie_entity, orders, products")]
        GXS["Gambio services<br/>OrderRead, OrderWrite, OrderStatus, DeleteHistory"]
    end

    PM --> PROC
    PM --> SVC
    OT --> BL
    SC --> PRV
    SC --> LCK
    SC --> WH
    PM --> UPD
    PM -->|"before_process"| PRV
    AC --> SVC
    AC --> PX
    MCM --> REPO
    OVL --> REG
    OVL --> BL

    PM -.->|"require autoload.php"| BOOT
    BOOT -->|"registers impls"| REG
    PROC --> MAP
    PROC --> BL
    PRV --> BL
    PRV --> LCK
    PRV -->|"status sync on return"| WH
    SVC --> GXS
    SVC --> RST
    MAP --> GXS
    RST --> REPO
    UPD --> REPO
    LCK --> DB

    WH --> BL
    WH -->|"events"| REG
    REG -->|"OrderTransitionService callback"| SVC
    BL --> PX
    BL --> REPO
    PX --> MAPI
    PX --> GH
    REPO --> DB
    GXS --> DB
```

## 3. Key components

| Component | Responsibility | Collaborators |
|---|---|---|
| `BootstrapComponent` (`Components/BootstrapComponent.php`) | Composition root. It extends the core `BootstrapComponent` and registers the Gambio implementations of `ShopLoggerAdapter`, `AuthorizationService` (`ApiKeyAuthService`), `ProxyDataProvider`, `Configuration`, `HttpClient` (`CurlHttpClient`), `PaymentMethodService`, `OrderTransitionService`, `OrderLineTransitionService`, `PaymentTransactionDescriptionService`, `VersionCheckService` and `MaintenanceModeService`. It maps `ConfigEntity`, `PaymentMethodConfig`, `Notification`, `StatusMapping`, `OrderReference` and `CustomerReference` to `BaseRepository`, and registers `PaymentProcessor` for both `payments_api` and `orders_api`. | `autoload.php` (calls `BootstrapComponent::init()` on every include), core `ServiceRegister`, `RepositoryRegistry` |
| `mollie` base payment module (`includes/modules/payment/mollie/mollie.php`) | Implements the Gambio payment-module contract (`selection`, `process_button`, `payment_action`, `after_process`, `get_error`, `install`/`remove`/`keys`/`check`). It filters availability by Mollie-enabled methods (`Proxy::getEnabledPaymentMethodsMap`) and by allowed zones, shows the surcharge, and runs `PaymentMethodUpdate::upsertConfigFields()` and `OrderStatusUpdate::addMissingStatuses()` in the constructor. `tmpOrders = true` makes Gambio create the order before `payment_action`. `before_process()` lets Gambio finish the checkout of an existing order (`tmp_oID`) only right after the return from Mollie (`MollieRedirectProvider::consumeReturnedOrder()`); any other submit, such as a double click or Back + resubmit, is redirected to `MollieRedirectProvider::getPaymentContinuationUrl()`. | `ProcessorFactory`, `Proxy`, `SurchargeService`, `ConfigurationService`, `GambioConfigRepository`, `MollieModuleChecker` |
| `mollie_issuer_providable` + `mollie_ideal`, `mollie_kbc`, `mollie_giftcard` | Issuer-list rendering and selection. The selected issuer goes into `$_SESSION['mollie_issuer']`. | `MollieIssuersProvider` |
| `mollie_creditcard` | Mollie Components card token (`$_SESSION['mollie_card_token']`) and single-click payments. Single-click creates a Mollie customer through `CustomerService` and stores `CustomerReference`. | `CustomerService`, `CustomerReferenceService`, `Shop/Html/mollie_credit_card.html` |
| `ot_mollie` (`includes/modules/order_total/ot_mollie.php`) | Adds the per-method surcharge (with its tax class) to `$order->info['total']`. | `SurchargeService` |
| `APIProcessor` (`ProcessorFactory`, `ProcessorRegister`, `PaymentProcessor`, `Result`) | Resolves the processor from the method's `PaymentMethodConfig::getApiMethod()`. `PaymentProcessor::create()` maps the order, calls `PaymentService::createPayment`, sets `mollie_created` and returns the checkout URL, or the `MollieCheckoutRedirect` URL when there is no checkout link. | `OrderMapper`, core `PaymentService`, `StatusUpdate` trait |
| `Mappers` (`OrderMapper`, `OrderTotalMapper`, `MapperUtility`, `OrderStatusMapper`) | Gambio `OrderInterface` → Mollie `Payment` or `Order` DTOs: lines, totals, gross/net tax handling, a "Tax adjustment" reconciliation line, addresses, locale, expiry, and issuer/card-token/customer from the session. `OrderStatusMapper` merges the saved `StatusMapping` with the default status IDs (`MOLLIE_DEFAULT_ORDER_STATUSES_MAP`) and adds defaults for keys the saved mapping lacks; canceled and failed default to status `99`, and `mollie_pending` falls back to `mollie_created` until the pending status exists. | `OrderRead` service, `ConfigurationService`, `TransactionDescriptionService`, `RepositoryRegistry` |
| `Services\Business\OrderTransitionService` | The core's callback on status change; also implements the optional core interface `PendingOrderTransitionService`. `payOrder`/`completeOrder` → `mollie_paid`, `authorizeOrder` → `mollie_authorized`, `refundOrder` → `mollie_refunded`. `cancelOrder`, `failOrder` and `expireOrder` → `mollie_canceled` with a comment plus `OrderResetService::resetOrder`, skipped when the order is already in the mapped `mollie_canceled` or Gambio's cancel status (`GM_ORDER_STATUS_CANCEL_ID`), so stock is restored once. `pendingOrder` → `mollie_pending` only for `mollie_paybybank` orders that are still in `mollie_created`. Comments use the shop's default language. | `StatusUpdate` (Gambio `OrderWrite::updateOrderStatus`), Gambio `OrderRead`, `OrderResetService`, `MollieTranslator`, `GambioLanguageRepository` |
| `Services\Business\OrderLineTransitionService` | Line-level callbacks. Cancel and refund push a `NotificationHub` info; the rest do nothing. | `NotificationHub` |
| `ConfigurationService` | Core `Configuration` implementation. It supplies the webhook URL (`shop.php?do=MollieWebhook`), the extension version from `composer.json`, the GX version from `release_info.php`, the version-check URL, and live/test key storage. | core `Configuration` (persists to `mollie_entity`), `UrlProvider` |
| `PaymentMethodService` | Builds `PaymentMethodConfig` from `gx_configurations` (`configuration/MODULE_PAYMENT_MOLLIE_*`) and defined constants. It always sets `API_METHOD_PAYMENT`. | `GambioConfigRepository` |
| `MaintenanceModeService`, `VersionCheckService`, `TransactionDescriptionService`, `LoggerService` | Gambio-specific hooks for core abstract services: `GM_SHOP_OFFLINE` via `LegacyDependencyContainer`, `messageStack` flash messages, per-language description constants, and `FileLog('mollie')`. | Gambio `messageStack`, `LanguageTextManager`, `FileLog` |
| `MollieRedirectProvider` | After the Mollie redirect (§5.2): checks that the order belongs to the session customer, then, for orders in `mollie_created`/`mollie_pending`, applies the current Mollie status through `WebHookTransformer` under the per-order `DatabaseLock`. Canceled orders go to `checkout_payment.php?payment_error=…` (current checkout) or `account_history_info.php`; the current checkout order gets the single-use session mark `mollie_returned_order` and goes to `checkout_process.php`; any other order goes to `checkout_success.php`. Also returns the continuation URL for a repeated submit (stored Mollie checkout link, or the return URL). | `OrderRead`, `OrderStatusMapper`, `OrderReferenceService`, core `WebHookTransformer`, `DatabaseLock` |
| `PaymentLinkProvider` | Creates a payment (or an Orders API order when `OrderReference.apiMethod` is `orders_api`) if the reference has no Mollie id yet, then returns `CheckoutLinkService::getCheckoutLink`. | `OrderReferenceService`, `PaymentService`, `OrderService`, `CheckoutLinkService`, `OrderMapper` |
| `OrderReset` (`OrderResetService`, `RestockService`, `ReactivateArticleService`, `ReshipService`) | Called from `OrderTransitionService` on cancel, fail and expiry. Undoes stock effects of the order: product/attribute/combi stock, specials quantity, `products_ordered`, product status and shipping status. It then reports the deletion to `DeleteHistoryService`. | `Gambio*Repository`, Gambio `ProductStockService`, `PropertiesCombisAdminControl`, `set_shipping_status()` |
| `Entity\Repository\BaseRepository` | Core `RepositoryInterface` over the single table `mollie_entity` (`type`, `index_1..7`, JSON `data`), using the CodeIgniter query builder. | `StaticGXCoreLoader::getDatabaseQueryBuilder()` |
| `Gambio*Repository` (`GambioBaseRepository` subclasses) | Raw-table access to `gx_configurations`, `orders_status`, `orders_products`, products, attributes, properties combis, specials, languages and countries. | CodeIgniter query builder |
| `GambioAuthorizationWrapper` | Validates and connects live/test API keys, stores them, and flashes the enabled methods. | `ApiKeyAuthService`, `PaymentMethodService`, `ConfigurationService` |
| `CustomFields` (`CustomFieldsProviderFactory` + providers) | Renders the Mollie-specific config fields (logo, multi-language names, API select, zones, surcharge, capture option, issuer list, components) inside Gambio's payment-module config page. | `mollie_config_fields.php` helpers, `Admin/Html/ConfigFields/*` |
| `MollieModuleCenterModule` | Install: creates `mollie_entity` and its indexes, creates five order statuses (Created/Paid/Authorized/Refunded/Pending (Mollie)) with a name in every shop language (`GambioStatusRepository::createStatus()`, English fallback), and stores `MOLLIE_DEFAULT_ORDER_STATUSES_MAP`. Uninstall: drops `mollie_entity`. `getDefaultStatuses()` exposes the status definitions to `OrderStatusUpdate`. | `GambioStatusRepository`, `GambioConfigRepository` |
| `Update\v3_1_6\OrderStatusUpdate` | Creates module statuses that were added after the shop was installed (for example "Pending (Mollie)") and adds them to `MOLLIE_DEFAULT_ORDER_STATUSES_MAP`. Runs once per request from the `mollie` constructor and the Module Center page, under the `order_status_update` lock, and re-reads the map from the DB because the constant can be stale. | `GambioStatusRepository`, `GambioConfigRepository`, `DatabaseLock` |
| `Utility\DatabaseLock` | MySQL named lock (`GET_LOCK`/`RELEASE_LOCK`) scoped to the shop database. `forOrder($id)` serializes the webhook (10 s, else HTTP 422) and the status sync on return (5 s, else skipped). A request holds at most one lock at a time. | CodeIgniter query builder |
| Admin controllers (`Admin/Classes/Controller/*`) | Module Center page (version check plus maintenance check on open), config save, key verification, notifications paging, refund/capture/payment-link popups, debug info, and logo upload. | core `Proxy`, `OrderReferenceService`, `NotificationChannelAdapter`, `DebugService` |
| Overload extenders (`Admin/Overloads/*`) | Hook Gambio admin actions: the order-detail dashboard (`Mollie_OrderExtender`), address/shipping/payment-type changes (`Mollie_OrderWriteServiceExtender`), order-line and total edits (`Mollie_AdminApplicationTopExtender`), cancel notifications (`Mollie_OrderActionExtender`), and custom config fields (`Mollie_ConfigurationBoxContentView`). | core `EventBus` (`Integration*Event`), `NotificationHub`, `OrderReferenceService` |

## 4. Domain model

Every entity and participant in sections 4 and 5 is labelled with the system that owns it:

| Label | Owner |
|---|---|
| `«gambio»` | The Gambio shop system (tables, services, checkout pages) |
| `«mollie-gambio»` | This module's own code (`GXModules/Mollie/Mollie`, `includes/modules`) |
| `«mollie-core»` | The vendored shared core `mollie/integration-core` |
| `«mollie-api»` | The external Mollie API (sequence diagrams only) |
| `«github»` | GitHub raw file host used for the version check (sequence diagrams only) |

Class diagrams put the label inside the class as an annotation (`<<gambio>>`). In sequence diagrams
the label is the first line of the participant alias, followed by `<br/>` and the name, with no
quotes: `participant GX as «gambio»<br/>checkout_process.php`. New diagrams, including those in
plans, follow the same convention.

`StatusMapping` (and the non-persisted `Result`) belong to this module. The `«mollie-core»` entities are defined by the core and persisted by this module's `BaseRepository` into `mollie_entity`. `MolliePayment` is the core's DTO of the Mollie API payment resource. `«gambio»` records are read or written through Gambio services and repositories.

```mermaid
classDiagram
    class GambioOrder {
        <<gambio>>
        +orders_id
        +orders_status
        +payment_class
        +currency
        +order_totals
    }
    class GambioOrderItem {
        <<gambio>>
        +orders_products_id
        +products_id
        +quantity
        +price
        +tax
    }
    class GxConfiguration {
        <<gambio>>
        +key "configuration/MODULE_PAYMENT_MOLLIE_*"
        +value
        +type
    }
    class OrderReference {
        <<mollie-core>>
        +shopReference
        +mollieReference
        +apiMethod "payments_api or orders_api"
        +payload "Payment or Order JSON"
    }
    class CustomerReference {
        <<mollie-core>>
        +shopReference
        +mollieReference
        +payload
    }
    class ConfigEntity {
        <<mollie-core>>
        +name
        +value
        +systemId
        +context
    }
    class PaymentMethodConfig {
        <<mollie-core>>
        +id
        +apiMethod
        +name
        +description
        +surchargeType
        +captureOption
        +originalAPIConfig
    }
    class Notification {
        <<mollie-core>>
        +severity
        +orderNumber
        +message
        +description
        +timestamp
    }
    class StatusMapping {
        <<mollie-gambio>>
        +statusMap "mollie_created|paid|authorized|refunded|pending|canceled|failed to orders_status_id"
    }
    class MolliePayment {
        <<mollie-core>>
        +id "tr_..."
        +status
        +amount
        +lines
        +webhookUrl
        +redirectUrl
    }
    class GambioOrderStatus {
        <<gambio>>
        +orders_status_id
        +orders_status_name
    }

    GambioOrder "1" *-- "many" GambioOrderItem
    GambioOrder "1" -- "0..1" OrderReference : shopReference
    OrderReference "1" --> "1" MolliePayment : mollieReference and payload
    GambioOrder "many" --> "1" GambioOrderStatus : orders_status
    StatusMapping "1" --> "many" GambioOrderStatus : maps Mollie states
    PaymentMethodConfig "1" ..> "many" GxConfiguration : built from
    CustomerReference "0..1" -- "1" GambioOrder : customer_id
    Notification "many" ..> "0..1" GambioOrder : orderNumber
    ConfigEntity ..> MolliePayment : API key, website profile
```

Persistence summary:
- `mollie_entity` holds `ConfigEntity`, `PaymentMethodConfig`, `Notification`, `StatusMapping`, `OrderReference` and `CustomerReference`. They are discriminated by `type` and have up to 7 string indexes.
- `gx_configurations` holds per-method settings (`configuration/MODULE_PAYMENT_MOLLIE_<ID>_*`), `configuration/MOLLIE_DEFAULT_ORDER_STATUSES_MAP` (JSON of the five module status keys to `orders_status_id`; keys added in later releases are appended by `OrderStatusUpdate`), `configuration/MODULE_ORDER_TOTAL_MOLLIE_*` and `MODULE_CENTER_MOLLIE_INSTALLED`.
- Gambio tables (`orders`, `orders_status`, `products*`, `specials`) are written for status updates, new statuses and order reset.
- MySQL named locks (`mollie_<8-char md5 of DB name>_order_<id>`, `…_order_status_update`) serialize concurrent work. They are not persisted and are released at the end of the callback or connection.

## 5. Key flows

### 5.1 Checkout: payment creation

```mermaid
sequenceDiagram
    actor Shopper
    participant GX as «gambio»<br/>checkout_process.php
    participant PM as «mollie-gambio»<br/>mollie_#lt;method#gt;
    participant PF as «mollie-gambio»<br/>ProcessorFactory
    participant PP as «mollie-gambio»<br/>PaymentProcessor
    participant OM as «mollie-gambio»<br/>OrderMapper
    participant PS as «mollie-core»<br/>PaymentService
    participant ORS as «mollie-core»<br/>OrderReferenceService
    participant API as «mollie-api»<br/>Mollie API

    Shopper->>GX: confirm order
    GX->>PM: process_button() stores issuer, card token, customer id in session
    GX->>PM: before_process() (no tmp_oID yet, nothing to do; see 5.2 for a repeated submit)
    GX->>GX: create order (tmpOrders = true), tmp_oID = order id
    GX->>PM: payment_action() with insert_id
    PM->>PM: _setOriginalConfig()
    PM->>PF: createProcessor(code)
    PF->>PF: PaymentMethodService config lookup (apiMethod = payments_api)
    PF-->>PM: PaymentProcessor
    PM->>PP: create(orderId)
    PP->>OM: getPayment(orderId)
    OM-->>PP: Payment DTO (lines, amount, webhookUrl, redirectUrl)
    PP->>PS: createPayment(orderId, payment)
    PS->>API: POST /v2/payments
    API-->>PS: payment with checkout link
    PS->>ORS: updateOrderReference(status null)
    PP->>PP: updateStatus(mollie_created) via OrderWrite
    PP-->>PM: Result(success, checkoutUrl)
    PM->>Shopper: xtc_redirect(checkoutUrl)
    Note over PM,Shopper: On failure the error is saved to session and the shopper is redirected to checkout_payment.php?payment_error=code
```

### 5.2 Redirect return

The shopper usually returns before the webhook is processed, so the return first applies the current
Mollie payment status through the same core path as the webhook (§5.3), serialized with it by the
per-order `DatabaseLock`. The core skips an unchanged status, so the later webhook is a no-op.

```mermaid
sequenceDiagram
    actor Shopper
    participant RC as «mollie-gambio»<br/>MollieCheckoutRedirectController
    participant RP as «mollie-gambio»<br/>MollieRedirectProvider
    participant OR as «gambio»<br/>OrderRead
    participant L as «mollie-gambio»<br/>DatabaseLock
    participant WT as «mollie-core»<br/>WebHookTransformer
    participant GX as «gambio»<br/>checkout_process.php
    participant PM as «mollie-gambio»<br/>mollie::before_process

    Shopper->>RC: GET shop.php?do=MollieCheckoutRedirect&order_id=N
    RC->>RP: getRedirectUrl(N)
    RP->>OR: getOrderById(N)
    alt order missing or not the session customer's
        RP-->>RC: account_history.php
    else
        opt status is mapped mollie_created or mollie_pending and the reference has a Mollie id
            RP->>L: forOrder(N).execute(5 s)
            L->>WT: handle(id=tr_x), as in 5.3 (cancel, fail and expiry reset the stock once)
            RP->>OR: getOrderById(N) again
        end
        alt status is mapped mollie_canceled
            RP-->>RC: checkout_payment.php?payment_error=module (N is tmp_oID) or account_history_info.php
        else N is not the current checkout order (tmp_oID)
            RP-->>RC: checkout_success.php
        else
            RP->>RP: session mollie_returned_order = N
            RP-->>RC: checkout_process.php
        end
    end
    RC-->>Shopper: RedirectHttpControllerResponse
    Shopper->>GX: checkout_process.php
    GX->>PM: before_process() consumes mollie_returned_order = tmp_oID
    GX->>GX: tmp_oID exists, so Gambio sends the order mail, calls after_process() and shows checkout_success.php
```

A failed sync (Mollie error, lock not acquired in 5 s) is logged as a warning and the decision uses
the current order status; the webhook corrects the status later.

**Repeated submit.** Gambio finishes the checkout whenever `checkout_process.php` is submitted while
`tmp_oID` exists. A double click on the order button, or Back from the Mollie page and another submit,
reaches `before_process()` without the `mollie_returned_order` mark. `before_process()` then
redirects to `getPaymentContinuationUrl(tmp_oID)`: the checkout link stored in the order's
`OrderReference` payload (same Mollie payment), or `MollieCheckoutRedirect` when there is none. The
fix relies on PHP sessions serializing the requests of one session.

### 5.3 Webhook status update

```mermaid
sequenceDiagram
    participant API as «mollie-api»<br/>Mollie API
    participant WC as «mollie-gambio»<br/>MollieWebhookController
    participant L as «mollie-gambio»<br/>DatabaseLock
    participant ORS as «mollie-core»<br/>OrderReferenceService
    participant WT as «mollie-core»<br/>WebHookTransformer
    participant PS as «mollie-core»<br/>PaymentService
    participant EB as «mollie-core»<br/>EventBus
    participant H as «mollie-core»<br/>StatusWebHookHandler / OrderRefundWebHookHandler
    participant OTS as «mollie-gambio»<br/>OrderTransitionService
    participant OW as «gambio»<br/>OrderWrite
    participant RS as «mollie-gambio»<br/>OrderResetService

    API->>WC: POST shop.php?do=MollieWebhook id=tr_x
    WC->>ORS: getByMollieReference(id)
    alt no reference
        WC->>API: GET /v2/payments/id (orderId fallback)
        Note over WC: still missing means MollieReferenceNotFoundException and HTTP 422
    end
    WC->>L: forOrder(shopReference).execute(10 s)
    Note over WC,L: lock held by the customer return or another webhook for more than 10 s means 422, Mollie retries
    WC->>WT: handle(raw body)
    WT->>API: GET /v2/payments/id (determineRequestId)
    WT->>ORS: getByMollieReference
    WT->>PS: getPayment(shopReference)
    PS->>API: GET /v2/payments/id
    WT->>EB: fire PaymentChangedWebHookEvent
    EB->>H: handle(event) when status changed
    H->>OTS: payOrder / authorizeOrder / pendingOrder / cancelOrder / failOrder / expireOrder / refundOrder
    Note over H,OTS: pendingOrder only when the service implements PendingOrderTransitionService
    OTS->>OW: updateOrderStatus(mapped id, comment)
    opt cancelOrder, failOrder or expireOrder on an order that is not canceled yet
        OTS->>RS: resetOrder(orderId)
    end
    WT->>ORS: updateOrderReference(new payload)
    WC-->>API: 200 success true, or 422 on exception
    Note over WT,API: HttpCommunicationException is rethrown and gives 422, so Mollie retries. Other API errors become Notifications and are not rethrown.
```

### 5.4 Admin refund and capture

```mermaid
sequenceDiagram
    actor Admin
    participant OE as «mollie-gambio»<br/>Mollie_OrderExtender
    participant RF as «mollie-gambio»<br/>MollieRefundController
    participant CP as «mollie-gambio»<br/>MollieCaptureController
    participant ORS as «mollie-core»<br/>OrderReferenceService
    participant PX as «mollie-core»<br/>Proxy
    participant API as «mollie-api»<br/>Mollie API

    Admin->>OE: open order detail (admin orders page)
    OE->>ORS: getByShopReference(oID)
    OE-->>Admin: dashboard with refund, capture and payment-link buttons based on stored payload status
    Admin->>RF: GET admin.php?do=MollieRefund&orders_id=N
    RF->>ORS: getByShopReference(N)
    RF->>PX: getPayment(tr_id)
    PX->>API: GET /v2/payments/tr_id
    RF-->>Admin: refund popup (availableForRefund)
    Admin->>RF: POST do=MollieRefund/processRefund JSON amount
    RF->>PX: createPaymentRefund(refund, tr_id)
    PX->>API: POST /v2/payments/tr_id/refunds
    RF-->>Admin: JSON success plus messageStack flash
    Admin->>CP: GET do=MollieCapture&transaction_id=tr_id
    CP->>PX: getCaptures(tr_id) and getPayment(tr_id)
    PX->>API: GET captures and GET payment
    Admin->>CP: POST do=MollieCapture/submitCapture amountForCapture
    CP->>PX: createCapture(capture, tr_id)
    PX->>API: POST /v2/payments/tr_id/captures
    Note over API: Status changes after refund or capture arrive through the webhook flow (5.3)
```

### 5.5 Payment link

```mermaid
sequenceDiagram
    actor Admin
    actor Customer
    participant PLC as «mollie-gambio»<br/>MolliePaymentLinkController
    participant PLR as «mollie-gambio»<br/>MolliePaymentLinkRedirectController
    participant PLP as «mollie-gambio»<br/>PaymentLinkProvider
    participant ORS as «mollie-core»<br/>OrderReferenceService
    participant PS as «mollie-core»<br/>PaymentService / OrderService
    participant CL as «mollie-core»<br/>CheckoutLinkService
    participant API as «mollie-api»<br/>Mollie API

    Admin->>PLC: GET admin.php?do=MolliePaymentLink&orders_id=N
    PLC-->>Admin: link shop.php?do=MolliePaymentLinkRedirect&order_id=N&token=md5(N + customerId)
    Admin->>Customer: send link
    Customer->>PLR: GET link (logged in)
    PLR->>PLR: token equals md5(order_id + session customer_id)?
    alt token invalid
        PLR-->>Customer: redirect login.php
    else valid
        PLR->>PLP: createPaymentAndGetCheckoutLink(N)
        PLP->>ORS: getByShopReference(N)
        opt reference exists without mollieReference
            PLP->>PS: createPayment or createOrder by apiMethod
            PS->>API: POST /v2/payments or /v2/orders
        end
        PLP->>CL: getCheckoutLink(N)
        CL->>API: GET payment or order
        PLR-->>Customer: redirect Mollie checkout
    end
    Note over PLR: Any exception is logged and the customer goes to account_history_info.php
```

The `OrderReference` without a `mollieReference` is created by `Mollie_OrderWriteServiceExtender::updatePaymentType` when an admin switches an order to a Mollie method.

### 5.6 Order reset on cancel, failure or expiry

```mermaid
sequenceDiagram
    participant T as «mollie-gambio»<br/>Trigger: OrderTransitionService cancelOrder, failOrder or expireOrder
    participant RS as «mollie-gambio»<br/>OrderResetService
    participant OPR as «mollie-gambio»<br/>GambioOrderProductRepository
    participant ST as «mollie-gambio»<br/>RestockService
    participant RA as «mollie-gambio»<br/>ReactivateArticleService
    participant SH as «mollie-gambio»<br/>ReshipService
    participant DH as «gambio»<br/>DeleteHistoryService

    T->>RS: resetOrder(orderId)
    RS->>OPR: getOrderProductsWithAttributes(orderId)
    loop each ordered product
        RS->>ST: restock(line, orderId): specials, product stock, products_ordered, combis, attributes
        RS->>RA: reactivate(line): products_status = 1 when stock is available
        RS->>SH: reship(line): set_shipping_status()
    end
    RS->>DH: reportDeletion(orderId, scope orders)
```

The transitions run from the webhook (§5.3) or from the status sync on return (§5.2), which are serialized per order. `OrderTransitionService::mapToCancelledStatus()` resets only when the order is not yet in the mapped `mollie_canceled` or Gambio's cancel status, so stock is restored once, whichever path (or the merchant's own cancel) comes first.

### 5.7 Connect and version check (admin)

```mermaid
sequenceDiagram
    actor Admin
    participant MC as «mollie-gambio»<br/>MollieModuleCenterModuleController
    participant VC as «mollie-gambio»<br/>VersionCheckService
    participant MM as «mollie-gambio»<br/>MaintenanceModeService
    participant CFG as «mollie-gambio»<br/>MollieConfigurationController
    participant AW as «mollie-gambio»<br/>GambioAuthorizationWrapper
    participant GH as «github»<br/>raw.githubusercontent.com
    participant API as «mollie-api»<br/>Mollie API

    Admin->>MC: admin.php?do=MollieModuleCenterModule
    MC->>VC: checkForNewVersion()
    VC->>GH: GET 4.5-5.x composer.json
    VC-->>Admin: messageStack info when newer version
    MC->>MM: checkMaintenanceMode() reads GM_SHOP_OFFLINE
    MC-->>Admin: redirect to MollieModuleCenterModule/Mollie config page
    Admin->>CFG: POST MOLLIE_TEST_MODE, tokens, orderStatuses
    CFG->>AW: connect()
    AW->>API: validate key, fetch profile and methods (core ApiKeyAuthService)
    CFG->>CFG: save StatusMapping in mollie_entity
    CFG-->>Admin: redirect back
```

## 6. Module map

| Path | Responsibility | Key entry points |
|---|---|---|
| `includes/modules/payment/` | Gambio payment modules, one class per Mollie method: alma, applepay, bancontact, banktransfer, belfius, billie, creditcard, eps, giftcard, ideal, kbc, klarna, klarnapaylater, klarnapaynow, klarnasliceit, paybybank, paypal, przelewy24, riverty, sofort, trustly, twint, wero. | `mollie/mollie.php` (base class `mollie`), `mollie/mollie_issuer_providable.php`, `mollie_creditcard.php`, `mollie_alma.php` / `mollie_billie.php` / `mollie_klarna.php` / `mollie_paybybank.php` / `mollie_riverty.php` / `mollie_twint.php` / `mollie_wero.php` (hide `API_METHOD`) |
| `includes/modules/order_total/` | Mollie surcharge order total | `ot_mollie.php` |
| `lang/<language>/` | Language constants for payment and order-total modules (dutch, english, french, german, italian, spanish) | `modules/payment/mollie_*.php`, `modules/order_total/mollie.php`, `original_sections/modules/order_total/ot_mollie.lang.inc.php` |
| `images/icons/payment/` | Default method logos (`mollie_<id>.png`); `images/mollie_connect.png` | referenced by `mollie::_getMethodLogo()` |
| `GXModules/Mollie/Mollie/autoload.php` | Loads the vendor autoloader and calls `BootstrapComponent::init()` | required by every entry point |
| `GXModules/Mollie/Mollie/composer.json` | Package `mollie/gambio` 3.1.6, PSR-4 `Mollie\Gambio\` → `Components`; version source for `getExtensionVersion()` and the remote version check | — |
| `GXModules/Mollie/Mollie/mollie_config_fields.php` | Global helper functions for Gambio config field rendering (`mollie_switcher`, `mollie_api_select`, `mollie_capture_select`, `mollie_multi_select_countries`, `mollie_logo_upload`, …) and `mollie_render_template()` (Smarty `ContentView`) | included by payment modules and extenders |
| `GXModules/Mollie/Mollie/Components/` | Wrapper PHP code (`Mollie\Gambio\*`) | `BootstrapComponent.php`, `APIProcessor/`, `Mappers/`, `Services/Business/`, `Services/Infrastructure/LoggerService.php`, `OrderReset/`, `MollieRedirect/`, `PaymentLink/`, `Entity/`, `CustomFields/`, `Authorization/`, `Utility/` (incl. `DatabaseLock.php`), `Debug/`, `Update/v2_0_8/PaymentMethodUpdate.php`, `Update/v3_1_6/OrderStatusUpdate.php`, `Exceptions/` |
| `GXModules/Mollie/Mollie/Shop/Classes/Controller/` | Storefront HTTP controllers (`shop.php?do=…`) | `MollieWebhookController`, `MollieCheckoutRedirectController`, `MolliePaymentLinkRedirectController` |
| `GXModules/Mollie/Mollie/Shop/{Html,Javascripts,Styles,Templates,Themes}` | Checkout UI: issuer list, credit-card Components, Apple Pay. Template/theme overrides inject scripts including `https://js.mollie.com/v1/mollie.js`. | `Themes/All/checkout_payment_modules.html`, `Templates/All/module/checkout_payment_block.html` |
| `GXModules/Mollie/Mollie/Admin/Classes/` | Module Center module and admin controllers (`admin.php?do=…`) | `MollieModuleCenterModule.inc.php`, `Controller/MollieModuleCenterModuleController`, `MollieConfigurationController`, `MollieConnectController`, `MollieRefundController`, `MollieCaptureController`, `MolliePaymentLinkController`, `MollieNotificationController`, `MollieSupportController`, `MollieFileUploadController` |
| `GXModules/Mollie/Mollie/Admin/Overloads/` | Gambio class overloads (folder = overloaded class) | `OrderExtenderComponent/Mollie_OrderExtender`, `OrderWriteService/Mollie_OrderWriteServiceExtender`, `AdminApplicationTopExtenderComponent/Mollie_AdminApplicationTopExtender`, `OrderActions/Mollie_OrderActionExtender`, `ConfigurationBoxContentView/Mollie_ConfigurationBoxContentView` |
| `GXModules/Mollie/Mollie/Admin/{Html,Javascripts,Styles,TextPhrases}` | Admin templates (config page, order dashboard, popups), JS (fetch wrappers, modals), and `module_center_module` / `admin_orders` phrase sections in 6 languages | `Html/mollie_configuration.html`, `Html/OrderDashboard/*` |
| `GXModules/Mollie/Mollie/vendor/` | Committed Composer output: `mollie/integration-core` 1.3.12 (identical to core commit `84a4bc9`, without `tests/` and CI files) plus the Composer autoloader | `vendor/mollie/integration-core/src/{BusinessLogic,Infrastructure}` |
| `.github/workflows/codeql.yml` | CodeQL scan (JavaScript only) on push/PR to `4.5-5.x` and weekly | — |

## 7. Key patterns & conventions

- **Composition root and service locator — the preferred way to resolve dependencies.** `autoload.php` calls `BootstrapComponent::init()`, which registers the wrapper implementations into the core `ServiceRegister`, maps entities to `BaseRepository` via `RepositoryRegistry`, and sets up `ProcessorRegister`. `BootstrapComponent::init()` + `ServiceRegister::getService(X::CLASS_NAME)` is the main and preferred way to resolve dependencies: register new services in `BootstrapComponent` and resolve them from `ServiceRegister`. Avoid instantiating services directly with `new`. Existing code still does this in places (`OrderResetService`, `MollieTranslator`, `Gambio*Repository`, and singletons via `getInstance()`); new code should not follow that pattern.
- **Core boundary interfaces.** The core calls the shop only through `Integration\Interfaces\OrderTransitionService` / `OrderLineTransitionService` (plus the optional `PendingOrderTransitionService`, which the core calls only when the registered `OrderTransitionService` implements it), `Configuration`, `ShopLoggerAdapter`, `RepositoryInterface`, and the abstract `VersionCheckService` / `MaintenanceModeService` / `PaymentTransactionDescriptionService`. Admin-side shop changes go to the core as `Integration*Event` objects on `EventBus`, fired by the overload extenders. Webhook-side changes come back as `PaymentChangedWebHookEvent` / `OrderChangedWebHookEvent` and are handled by the core handlers. `WebHookContext` suppresses integration-event handlers while a webhook is running, so there is no echo back to Mollie.
- **Persistence.**
  - Core entities go into one generic table, `mollie_entity` (`type` + `index_1..7` + JSON `data`), created in `MollieModuleCenterModule::install()` and dropped on uninstall. There are no migrations; the schema is fixed.
  - Payment-method settings use Gambio's config table `gx_configurations` with keys `configuration/MODULE_PAYMENT_<CODE>_<FIELD>`, read back as PHP constants (`defined()`/`constant()`).
  - Missing fields are upserted at runtime by `PaymentMethodUpdate` (constructor of every payment module), by `mollie_issuer_providable` / `mollie_creditcard` (initial `ISSUER_LIST`, `COMPONENTS_STATUS`, `SINGLE_CLICK_STATUS`), and by `ot_mollie` (sort-order type). Module order statuses added after install are created at runtime by `OrderStatusUpdate` (payment-module constructor and Module Center page).
  - Direct SQL must use the CodeIgniter query builder (`StaticGXCoreLoader::getDatabaseQueryBuilder()`). Avoid `xtc_db_query` wherever possible; use it only where Gambio itself dictates it (existing uses: `mollie.php`, `ot_mollie.php`, `MollieModuleChecker`).
- **Gambio overload/extender mechanism.** A class `Mollie_<X>` extends the generated `Mollie_<X>_parent`, placed under `Admin/Overloads/<OverloadedClass>/`. Gambio's `MainFactory` chains it into the original class, so each method calls `parent::…` to keep the chain working. Payment modules end with `MainFactory::load_origin_class('mollie')`. GXModules controllers are discovered by convention: `<Name>Controller` extends `HttpViewController` (shop) or `AdminHttpViewController` (admin), with `action<Name>()` methods, and returns `MainFactory::create('…HttpControllerResponse', …)`.
- **Naming.**
  - Gambio payment code is `mollie_<mollieMethodId>`; the Mollie method id is the code minus the `mollie_` prefix (`MapperUtility::_formatPaymentMethod`).
  - Mollie status keys are `mollie_created|paid|authorized|refunded|pending|canceled|failed`.
  - Underscore-prefixed method names (`_getX`) are used throughout.
  - Wrapper classes are `Mollie\Gambio\<Area>\<Name>`; Gambio-visible classes are global and `.inc.php`.
- **Session as the transport between checkout steps.** `mollie_issuer`, `mollie_card_token`, `mollie_customer_id` and `<code>_error` are written in `process_button()` / `payment_action()` and consumed once in `MapperUtility::addSpecificParameters()` / `get_error()`. `mollie_returned_order` is written by `MollieRedirectProvider` on return and consumed once in `mollie::before_process()`.
- **Concurrency.** Work that can run in parallel for the same order (webhook, status sync on return) or for the same setup step (`OrderStatusUpdate`) runs inside `DatabaseLock::execute()`. A request holds at most one named lock at a time, because MySQL before 5.7 releases the held lock when another one is acquired. Concurrent checkout submits of one shopper are serialized by the PHP session lock.
- **API selection.** `ProcessorRegister` accepts `payments_api` and `orders_api` but maps both to `PaymentProcessor`. `PaymentMethodService::extractLocalConfiguration` always returns `payments_api`, so checkout always uses the Payments API (release 3.1.0, "Remove usage of Orders API"). Capture is offered only for `mollie_billie`, `mollie_creditcard`, `mollie_klarna` and `mollie_riverty` payments in status `authorized` (`Mollie_OrderExtender::$capturableMethods`). Orders API code paths remain for older `OrderReference` records created with the Orders API (`Mollie_OrderExtender`, `MollieCaptureController::_handleOrderTransaction`, `MollieRefundController::getTransactionId`, `PaymentLinkProvider`).
- **Error surfacing.** Admin actions catch `Exception` and flash through `$GLOBALS['messageStack']`. Core-side problems become `NotificationHub` entries, shown in the Module Center notifications tab. Logging goes to Gambio `FileLog('mollie')`, filtered by `minLogLevel` or debug mode.

## 8. External boundaries

| Boundary | Direction | Details |
|---|---|---|
| Mollie API v2 (`https://api.mollie.com/v2/`; reference: https://docs.mollie.com/reference/overview) | outbound | Through core `Proxy` + `CurlHttpClient`, bearer API key from `ConfigEntity`. Calls: `GET methods` (enabled/all methods on every checkout page and admin page), `POST payments`, `GET payments/{id}` (webhook, and the status sync on return), `POST payments/{id}/refunds`, `GET/POST payments/{id}/captures`, `POST customers` (single-click), `GET orders/{id}` and `POST orders` (older Orders API references only), and key validation / profile lookup on connect. |
| Mollie webhook → `shop.php?do=MollieWebhook` | inbound | Form POST with `id`. There is no signature; authenticity comes from fetching the resource back from the API by id. One webhook makes up to 3 `GET /payments/{id}` calls (`_getOrderReferenceFromPayment` fallback, `determineRequestId`, `PaymentService::getPayment`). It runs under the per-order `DatabaseLock` and replies 422 on a missing reference, when the lock is not acquired within 10 s, on any exception, or on core-rethrown `HttpCommunicationException`, so Mollie retries. |
| Mollie hosted checkout → `shop.php?do=MollieCheckoutRedirect&order_id=N` | inbound (browser) | `redirectUrl` in the payment. Acts only when the order belongs to the session customer (otherwise `account_history.php`, no change). For an order still in created/pending it applies the current Mollie status through the webhook path (the same `GET /payments/{id}` calls as one webhook), under the per-order lock. It finishes the checkout only for the current checkout order (`tmp_oID`). |
| Payment link → `shop.php?do=MolliePaymentLinkRedirect&order_id=N&token=…` | inbound (browser) | Token is `md5(order_id . customer_id)`, checked against the session customer. |
| Mollie Components JS (`https://js.mollie.com/v1/mollie.js`) | browser | Card tokenisation (profile id, test mode, locale), loaded by the checkout template overrides. |
| Version check (`https://raw.githubusercontent.com/mollie/gambio/4.5-5.x/GXModules/Mollie/Mollie/composer.json`) | outbound | Through `VersionCheckProxy` on every open of the Module Center entry action. Download link: `https://github.com/mollie/gambio/releases/tag/v<version>`. |
| Gambio core services | in-process | `StaticGXCoreLoader::getService('OrderRead'|'OrderWrite'|'OrderStatus')`, `DeleteHistoryServiceFactory`, `LegacyDependencyContainer` (`Gambio\Core\Configuration\ConfigurationService`), `LanguageTextManager`, `messageStack`, `FileLog`, `ContentView`, and the `xtc_*` functions. |

## 9. Known constraints

- **PHP floor.**
  - `composer.json` declares `"php": ">=5.4"`; the core declares `>=5.3`.
  - Wrapper code uses PHP 5.4 features (short arrays, traits, `static function` closures) and nothing newer (no `??`, no scalar or return type declarations).
  - The code must also run on PHP 8.x (3.0.18 added PHP 8.2 compatibility). Guard every constant lookup with `defined()`, because an undefined `constant()` throws on PHP 8 (3.1.5).
- **Gambio range.** This branch (`4.5-5.x`) supports GX 4.5.x–5.0.x. The only other actively maintained line is GX 4.1.x–4.4.x on branch `4.1-4.4`; fixes that apply there are ported by hand. All other branches and GX versions are out of scope. The code relies on GX 4.x APIs (`LegacyDependencyContainer`, `gx_configurations` with the `configuration/` key prefix, the CodeIgniter query builder).
- **Branch differences (`4.1-4.4`).** These are the same layers and flows, with branch-specific details:
  - `gx_configurations` rows are inserted with `legacy_group_id = 6`.
  - Constants are read with `@constant(...)`, with no `_getConstantValue()` helper.
  - `PaymentMethodUpdate::addConfigFields()` has no sort-order type update.
  - `Mollie_AdminApplicationTopExtender::proceed(): void`.
  - Languages are de/en/fr/nl only.
  - `VERSION_CHECK_URL` and the CodeQL workflow point at `4.1-4.4`.
  - `OrderStatusUpdate` lives in `Update\v2_1_5` and, because `GambioConfigRepository` has no `select/update` there, reads the status map with `getByKey()` and writes it with `updateValue()`.
  - The vendored core is the same as on this branch: 1.3.12, identical to core commit `84a4bc9`. It is still installed with Composer on each branch, never copied.
  - Pay by Bank, the pending status, the status sync on return and the repeated-submit guard are in release 3.1.6 on this branch and in release 2.1.5 on `4.1-4.4`.
- **Vendored core.** `vendor/` is committed. `mollie/integration-core` comes from a private VCS repo (`git@github.com:mollie/orocore.git`), so `composer install` needs access to it. The core is never edited in place.
- **Single store.** `getCurrentSystemId()` returns `'1'`; there is no store scoping.
- **Manual verification.** There is no automated test suite or quality gate. Changes are verified on a Gambio install (copy files, clear the module/output/text cache, install via Module Center).
- **Session locking.** The repeated-submit guard (§5.2) relies on Gambio's PHP session handler serializing the requests of one session (the default file handler does).

## 10. Architecture decisions

Short ADRs for significant decisions. Feature details are in `docs/specs/<feature>/`.

**ADR-1: Synchronize the payment status on return through the webhook path** (3.1.6, `docs/specs/payment-status-on-return/`)
- *What:* On return, orders still in created/pending run `WebHookTransformer::handle(id)` inside a per-order MySQL named lock that the webhook also takes. Restock moves into the idempotent cancel transition.
- *Why:* The shopper usually returns before the webhook, so the order status alone sent canceled payments to the success page. Reusing the webhook path keeps one implementation of every transition; the core skips unchanged statuses, so the later webhook is a no-op.
- *Cost:* Up to 3 extra Mollie GETs per return of an unconfirmed order; a return can wait up to 5 s for a running webhook; a failed sync falls back to the order status (fail-open).

**ADR-2: Guard the checkout finish in `before_process()`** (3.1.6, `docs/specs/repeated-order-submit/`)
- *What:* Gambio may finish the checkout of an existing order only when the single-use session mark set by the return matches `tmp_oID`; any other submit is redirected to the existing Mollie payment.
- *Why:* Gambio finishes the checkout on any submit while `tmp_oID` exists, so a double click sent the confirmation mail before payment. `before_process()` is called before that decision on every GX version in range and needs no overload.
- *Cost:* Relies on session request serialization; a repeated submit after a failed payment creation still has no payment to continue to (open in the spec).

**ADR-3: Optional core interface for pending payments** (core 1.3.12, `docs/specs/pay-by-bank/`)
- *What:* `PendingOrderTransitionService` is a separate optional interface; `StatusWebHookHandler` calls `pendingOrder()` only when the registered transition service implements it. The Gambio wrapper uses it only for `mollie_paybybank`.
- *Why:* The core is shared by several integrations; adding the method to `OrderTransitionService` would break the ones that don't implement it.
- *Cost:* One `instanceof` check in the core; integrations must opt in explicitly.

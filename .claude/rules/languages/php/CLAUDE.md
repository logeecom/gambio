---
paths:
  - "includes/**/*.php"
  - "lang/**/*.php"
  - "GXModules/Mollie/Mollie/*.php"
  - "GXModules/Mollie/Mollie/Components/**/*.php"
  - "GXModules/Mollie/Mollie/Admin/**/*.php"
  - "GXModules/Mollie/Mollie/Shop/**/*.php"
---

# PHP Security Rules (Gambio module)

Security rules for the PHP code of this module. `vendor/` is out of scope, because it is never edited in place.

## Prerequisites

- `.claude/rules/_core/owasp-2025.md`: core web security
- `.claude/rules/_core/asvs-l2.md`: the verification standard and checklist for this repo

Every rule has to work on the **PHP 5.4 syntax floor** and on PHP 8.x (see `CLAUDE.md` § Local rules).

---

## Database Access

### Rule: Use the CodeIgniter query builder with bindings

**Level**: `strict`

**When**: Any read or write to the database.

**Do**:
```php
$db = StaticGXCoreLoader::getDatabaseQueryBuilder();
$row = $db->get_where('orders', ['orders_id' => (int)$orderId])->row_array();
$db->update('orders', ['orders_status' => (int)$statusId], ['orders_id' => (int)$orderId]);
```

**Don't**:
```php
xtc_db_query("UPDATE orders SET orders_status = " . $_GET['status'] . " WHERE orders_id = " . $_GET['oID']);
```

**Why**: Request values interpolated into SQL are injection. `xtc_db_query` is allowed only where Gambio itself dictates it (for example the payment-module `install`/`check`/`remove` contract), and then only with cast or `xtc_db_input()`-escaped values.

**Refs**: OWASP A05:2025, CWE-89, ASVS 1.3

---

## Input Handling

### Rule: Validate or cast every request value at the entry point

**Level**: `strict`

**When**: Reading `$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`, controller `_getQueryParameter()` / `_getPostData()`, the webhook body, or data returned by the Mollie API.

**Do**:
```php
$orderId = (int)$this->_getQueryParameter('orders_id');
if ($orderId <= 0) {
    return MainFactory::create('JsonHttpControllerResponse', ['success' => false]);
}
$apiMethod = in_array($value, [PaymentMethodConfig::API_METHOD_PAYMENT], true) ? $value : null;
```

**Don't**:
```php
$orderId = $_GET['orders_id'];                 // used raw in a query, path or template
$amount  = $_POST['amount'];                   // sent to Mollie as-is
```

**Why**: Controllers and extenders are the trust boundary. Everything below them assumes validated types.

**Refs**: OWASP A05:2025, CWE-20, ASVS 2.1

---

## Output Encoding

### Rule: Escape for the sink; never disable template escaping for untrusted data

**Level**: `strict`

**When**: Rendering data in Smarty templates (`Admin/Html`, `Shop/Html`, `Shop/Templates`, `Shop/Themes`) or building HTML, attributes or JS in PHP.

**Do**:
```php
echo '<span title="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
```
```smarty
{$paymentMethodName}            {* default escaping stays on *}
```

**Don't**:
```smarty
{$orderComment nofilter}        {* only trusted text phrases may use nofilter/unescape *}
```

**Why**: Order data, customer names and Mollie error messages end up in admin and shop pages, so they are XSS vectors.

**Refs**: OWASP A05:2025, CWE-79, ASVS 1.1

---

## Access Control

### Rule: Admin functionality lives only in admin controllers; shop endpoints verify order ownership

**Level**: `strict`

**When**: Adding or changing an HTTP controller (`shop.php?do=…` / `admin.php?do=…`).

**Do**:
- Admin: extend `AdminHttpViewController`, accept state changes only via POST, and validate an anti-CSRF token. Confirm the mechanism for the supported GX versions before use.
- Shop: for any `order_id` parameter, check that the order belongs to `$_SESSION['customer_id']`, or require an HMAC token.

**Don't**:
- Expose refund, capture, config or upload actions through a `HttpViewController` (shop) controller.
- Act on an order ID from the URL without an ownership check.

**Why**: Shop controllers are reachable by anyone on the internet.

**Refs**: OWASP A01:2025, CWE-284, CWE-352, ASVS 4.2, 4.3, 8.1

---

## Webhook

### Rule: Trust only what is fetched back from Mollie; stay idempotent

**Level**: `strict`

**When**: Changing `MollieWebhookController` or the core webhook handlers it invokes.

**Do**:
- Read only the `id` from the request, and fetch the payment through the core `Proxy` / `PaymentService`.
- Make status updates and order reset safe to run more than once (Mollie retries on non-2xx).
- Return a non-2xx status only when a retry makes sense.

**Don't**:
- Read status, amount or metadata from the webhook POST body.
- Perform side effects (restock, emails) without checking whether they have already happened.

**Refs**: OWASP A08:2025, CWE-345, ASVS 4.4

---

## File Uploads

### Rule: Allow-list type and size, and generate the file name server-side

**Level**: `strict`

**When**: Handling `$_FILES`.

**Do**:
```php
$allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
$ext  = strtolower(pathinfo($_FILES['uploadFile']['name'], PATHINFO_EXTENSION));
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['uploadFile']['tmp_name']);
if (!isset($allowed[$ext]) || $allowed[$ext] !== $mime || $_FILES['uploadFile']['size'] > 1048576) {
    throw new FileUploadException('Invalid file');
}
$target = DIR_FS_CATALOG_IMAGES . 'mollie_' . bin2hex(openssl_random_pseudo_bytes(8)) . '.' . $ext;
```

**Don't**:
```php
move_uploaded_file($_FILES['f']['tmp_name'], DIR_FS_CATALOG_IMAGES . basename($_FILES['f']['name']));
```

**Refs**: OWASP A06:2025, CWE-434, ASVS 5.1–5.3

---

## Secrets and Tokens

### Rule: No keys in code or logs; tokens are HMAC/CSPRNG with constant-time comparison

**Level**: `strict`

**When**: Handling Mollie API keys, building links or tokens, or logging.

**Do**:
- Read keys only through `ConfigurationService`. Never log them or render them back in full.
- Build tokens with `hash_hmac('sha256', $data, $secret)` and compare them with `hash_equals` (polyfill below PHP 5.6).
- Log Mollie IDs (`tr_…`) and order IDs, not payloads with addresses or emails.

**Don't**:
- `md5($orderId . $customerId)` as an access token.
- `rand()`, `mt_rand()` or `uniqid()` for anything security-relevant.
- `FileLog` of full API responses or request bodies.

**Refs**: OWASP A04:2025, A09:2025, CWE-330, CWE-532, ASVS 11.1–11.3, 14.1

---

## Dangerous Functions

### Rule: No dynamic code execution or unsafe deserialization

**Level**: `strict`

**When**: Always.

**Don't**: `eval`, `assert` with strings, `create_function`, `preg_replace` with `/e`, `exec` / `shell_exec` / `system` / `passthru` / backticks, or `unserialize` on anything not written by this module (use `json_decode`). Never pass a variable to `include` / `require`.

**Refs**: OWASP A05:2025, CWE-94, CWE-502

---

## Error Handling

### Rule: Catch at entry points; show generic messages

**Level**: `warning`

**When**: Controllers, overload extenders, payment-module callbacks.

**Do**: At the entry point, use `catch (\Exception $e)` followed by `catch (\Throwable $e)`. The second block is valid on PHP 5, where it never matches, and it catches `Error` on PHP 7+. Log the details, then show a generic `messageStack` message or error code.

**Don't**: Let exceptions reach Gambio's output, or print raw Mollie API error bodies to shoppers.

**Refs**: OWASP A10:2025, CWE-209

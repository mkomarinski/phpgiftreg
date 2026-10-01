# PHP Gift Registry (phpgiftreg)

The PHP Gift Registry is a web-enabled gift registry intended for use among 
a circle of family members or friends.  

It is intended to fill the following purposes:

* Permit the long-term storage of a list of items one desires, along with its price, where it can be bought, and (optionally) a URL where it can be 
  viewed.
* Enabled items to be "locked" by one shopper so that the same item is not bought by someone else.

Its features include:

* A single unifying view of items on your own list and people whose lists you can view.
* A now-optional request/permit system by which you can control who can see your list.
* A "checkin/checkout" system which allows you to reserve items on someone's list.
* An in-system messaging system by which users can be informed of item deletions or custom announcements.
* New users can request accounts.  Optionally, administrators will be  informed about the request, and they can then approve or reject the request.  Either way, the user will be informed by e-mail.
* A site-customizable ranking system for items.
* An events system for users to add significant (read: gift-bearing) events which will show up on others' displays when the event nears.
* Optional OpenID Connect (OIDC) single sign-on, with configurable user provisioning and approval.

## OpenID Connect (OIDC)

Enable OIDC login from the administrator Settings page. Configure the provider's issuer URL, client ID, client secret, and scopes (by default, `openid email profile`). User provisioning and approval can be controlled separately.

Register this callback URL with your identity provider, replacing the hostname with your deployment's public URL:

```text
https://registry.example.org/login.php?action=oidc_callback
```

For Docker deployments using `docker-compose.prod.yml`, set `OIDC_REDIRECT_URI` in the project `.env` file to the exact registered callback URL. This pins the URL used by both the authorization request and token exchange, which is useful when HTTPS terminates at a reverse proxy. Recreate the app container after changing the value. If unset, the application derives the callback URL from the incoming request and proxy headers.

## Installing

Read [INSTALL](INSTALL) for installation instructions.

If you have any questions, comments, feature requests, or patches, feel free to e-mail me.

## License

phpgiftreg is licensed by the GPL.  For more information on the GPL, visit http://www.gnu.org

Copyright 2022 Ryan Walberg <generalpf@gmail.com> [@GeneralPeeEff](https://twitter.com/GeneralPeeEff)

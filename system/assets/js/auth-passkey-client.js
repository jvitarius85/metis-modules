(function () {
    var form = document.getElementById("metis-auth-resolve-form");
    if (!form) {
        return;
    }

    var passwordCard = document.getElementById("metis-password-card");
    var passwordForm = document.getElementById("metis-password-form");
    var googleForm = document.getElementById("metis-google-sso-form");
    var googleButton = document.getElementById("metis-google-sso-button");
    var passwordIdentifier = document.getElementById("metis-password-identifier");
    var passwordInput = document.getElementById("metis-password-input");
    var resolveButton = document.getElementById("metis-auth-resolve-button");
    var unsupported = document.getElementById("metis-passkey-unsupported");
    var identifier = document.getElementById("identifier");
    var status = document.getElementById("metis-auth-status");
    var completeUrl = form.dataset.completeUrl || "";
    var resolveUrl = form.dataset.resolveUrl || "";
    var resolveNonce = form.dataset.resolveNonce || "";
    var beginNonce = form.dataset.beginNonce || "";
    var completeNonce = form.dataset.completeNonce || "";
    var completeAction = form.dataset.completeAction || "";
    var redirect = form.dataset.redirect || "";

    function navigate(url) {
        var target = String(url || "").trim();
        if (!target) {
            return false;
        }
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === "function") {
            return Metis.navigation.go(target);
        }
        window.location.assign(target);
        return true;
    }

    function setStatus(message, isError) {
        if (!status) {
            return;
        }
        status.textContent = message || "";
        status.style.color = isError ? "#8a2a1f" : "#1f3556";
    }

    function b64urlToBytes(value) {
        var base = String(value || "").replace(/-/g, "+").replace(/_/g, "/");
        while (base.length % 4) {
            base += "=";
        }
        var raw = window.atob(base);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i += 1) {
            out[i] = raw.charCodeAt(i);
        }
        return out;
    }

    function bytesToB64url(value) {
        var bytes = value instanceof Uint8Array ? value : new Uint8Array(value || []);
        var binary = "";
        for (var i = 0; i < bytes.length; i += 1) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
    }

    function normalizeRequest(publicKey) {
        var next = Object.assign({}, publicKey || {});
        next.challenge = b64urlToBytes(next.challenge);
        if (Array.isArray(next.allowCredentials)) {
            next.allowCredentials = next.allowCredentials.map(function (item) {
                return Object.assign({}, item, { id: b64urlToBytes(item.id) });
            });
        }
        return next;
    }

    function postForm(url, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (key) {
            body.set(key, String(payload[key] ?? ""));
        });
        return fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            credentials: "same-origin",
            body: body.toString()
        }).then(function (resp) {
            return resp.json().catch(function () {
                return { success: false, data: { message: "Invalid server response." } };
            });
        });
    }

    function showPassword(identifierValue) {
        if (passwordCard) {
            passwordCard.hidden = false;
        }
        if (passwordIdentifier) {
            passwordIdentifier.value = String(identifierValue || "");
        }
        if (passwordInput) {
            window.setTimeout(function () {
                passwordInput.focus();
            }, 0);
        }
    }

    function hasMethod(data, method) {
        return Array.isArray(data && data.methods) && data.methods.indexOf(method) !== -1;
    }

    function startGoogleWorkspace() {
        if (!googleForm) {
            throw new Error("Google Workspace sign-in is unavailable.");
        }

        setStatus("Opening Google Workspace sign-in...", false);
        if (googleButton) {
            googleButton.disabled = true;
        }
        if (typeof googleForm.requestSubmit === "function") {
            googleForm.requestSubmit();
        } else {
            googleForm.submit();
        }
        window.setTimeout(function () {
            if (googleButton) {
                googleButton.disabled = false;
            }
        }, 2500);
    }

    function startPasskey(data) {
        if (!window.PublicKeyCredential) {
            if (unsupported) {
                unsupported.hidden = false;
            }
            throw new Error("This browser does not support passkey sign-in.");
        }

        setStatus("Waiting for your passkey device...", false);
        return navigator.credentials.get({ publicKey: normalizeRequest((data && data.public_key) || {}) })
            .then(function (credential) {
                if (!credential || !credential.response) {
                    throw new Error("No passkey response was returned.");
                }
                return postForm(completeUrl, {
                    challenge_key: (data && data.challenge_key) || "",
                    credential_id: bytesToB64url(credential.rawId),
                    client_data_json: bytesToB64url(credential.response.clientDataJSON),
                    authenticator_data: bytesToB64url(credential.response.authenticatorData),
                    signature: bytesToB64url(credential.response.signature),
                    user_handle: credential.response.userHandle ? bytesToB64url(credential.response.userHandle) : "",
                    redirect_to: redirect,
                    csrf_token: completeNonce,
                    metis_csrf_action: completeAction
                });
            })
            .then(function (payload) {
                if (!payload || payload.success !== true || !payload.data) {
                    throw new Error((payload && payload.data && payload.data.message) || "Passkey sign-in failed.");
                }
                navigate(String(payload.data.redirect_url || redirect));
            });
    }

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        if (resolveButton) {
            resolveButton.disabled = true;
        }
        if (passwordCard) {
            passwordCard.hidden = true;
        }
        setStatus("Checking your account...", false);
        postForm(resolveUrl, {
            identifier: (identifier && identifier.value) || "",
            redirect_to: redirect,
            csrf_token: resolveNonce,
            metis_csrf_action: "metis_auth_resolve",
            passkey_begin_csrf_token: beginNonce
        }).then(function (payload) {
            if (!payload || payload.success !== true || !payload.data) {
                throw new Error((payload && payload.data && payload.data.message) || "Sign-in could not be started.");
            }
            var data = payload.data;
            var method = String(data.method || "");
            if (method === "password") {
                showPassword(data.identifier || ((identifier && identifier.value) || ""));
                setStatus("Enter your password to continue.", false);
                return null;
            }
            if (method === "passkey") {
                return startPasskey(data.passkey || {}).catch(function () {
                    if (data.password_fallback) {
                        showPassword(data.identifier || ((identifier && identifier.value) || ""));
                        setStatus("Passkey unavailable. Enter your password to continue.", false);
                        return null;
                    }
                    if (hasMethod(data, "google_workspace")) {
                        startGoogleWorkspace();
                        return null;
                    }
                    setStatus("Passkey sign-in is unavailable for this account.", true);
                    return null;
                });
            }
            if (method === "google_workspace") {
                startGoogleWorkspace();
                return null;
            }
            throw new Error("Unsupported authentication method.");
        }).catch(function (error) {
            if (resolveButton) {
                resolveButton.disabled = false;
            }
            setStatus(error && error.message ? error.message : "Sign-in failed.", true);
        });
    });

    if (googleButton && googleForm) {
        googleButton.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            try {
                startGoogleWorkspace();
            } catch (error) {
                if (googleButton) {
                    googleButton.disabled = false;
                }
                setStatus(error && error.message ? error.message : "Google sign-in could not be started.", true);
            }
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", function () {
            setStatus("Verifying password...", false);
        });
    }
}());

(function () {
    'use strict';

    var section = document.getElementById('easy-mcp-key-create');
    if (!section) {
        return;
    }

    var expiry = document.getElementById('easy-mcp-key-expiry');
    var custom = section.querySelector('.easy-mcp-key-custom-expiry');
    var date = document.getElementById('easy-mcp-key-custom-date');
    function syncExpiry() {
        var selected = expiry.value === 'custom';
        custom.hidden = !selected;
        date.disabled = !selected;
        date.required = selected;
    }
    expiry.addEventListener('change', syncExpiry);
    syncExpiry();

    var controls = section.querySelector('.easy-mcp-key-selection');
    var list = section.querySelector('.easy-mcp-key-tools');
    var generate = document.getElementById('easy-mcp-key-generate');
    var error = section.querySelector('.easy-mcp-key-error');

    function toolInputs() {
        return section.querySelectorAll('input[name="key_tools[]"]');
    }

    function syncControls() {
        var count = toolInputs().length;
        if (controls) {
            controls.hidden = !count;
        }
        if (generate) {
            generate.disabled = !count;
        }
    }

    if (controls) {
        controls.addEventListener('click', function (event) {
            var button = event.target.closest('[data-select-tools]');
            if (!button || !controls.contains(button)) {
                return;
            }
            toolInputs().forEach(function (tool) {
                if (!tool.disabled) {
                    tool.checked = button.dataset.selectTools === 'all';
                    tool.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    }
    if (toolInputs().length && controls) {
        controls.hidden = false;
    }

    // GitHub #1064: profile.php is not a request this plugin serves, so the list rendered there
    // leaves out the LearnPress, Elementor, Breakdance and LatePoint abilities. The list and the
    // create are asked of Self_Service_Routes, where they are registered. If the list cannot be
    // read, the rendered list, its "Not listed here" sentence and the admin-post form stay exactly
    // as they were. Once the served list is shown, a refused or failed create shows its message and
    // the user may try again; the served list stays, as the rendered one would drop those tools.
    var config = window.easyMcpSelfServiceKeys;
    var form = document.getElementById('easy-mcp-self-create');
    if (!config || !list || !form || !window.fetch) {
        return;
    }

    function row(tool) {
        var wrapper = document.createElement('div');
        wrapper.className = 'wp-mcp-tool-row';
        wrapper.setAttribute('data-tool-name', tool.name);
        var label = document.createElement('label');
        label.className = 'wp-mcp-tool-label';
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'key_tools[]';
        input.value = tool.name;
        input.setAttribute('form', 'easy-mcp-self-create');
        var info = document.createElement('span');
        info.className = 'wp-mcp-tool-info';
        var name = document.createElement('code');
        name.className = 'wp-mcp-tool-name';
        name.textContent = tool.name;
        info.appendChild(name);
        if (tool.description) {
            var description = document.createElement('span');
            description.className = 'wp-mcp-tool-description';
            description.textContent = tool.description;
            info.appendChild(description);
        }
        label.appendChild(input);
        label.appendChild(info);
        wrapper.appendChild(label);
        return wrapper;
    }

    function render(tools) {
        var checked = {};
        toolInputs().forEach(function (input) {
            checked[input.value] = input.checked;
        });
        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }
        tools.forEach(function (tool) {
            var element = row(tool);
            element.querySelector('input').checked = !!checked[tool.name];
            list.appendChild(element);
        });
        if (!tools.length) {
            var none = document.createElement('p');
            none.className = 'wp-mcp-tool-row easy-mcp-key-no-tools';
            none.textContent = config.noTools;
            list.appendChild(none);
        }
        syncControls();
    }

    // One id per attempt, sent again on a retry: the route then answers with the key a create whose
    // reply was lost already made, instead of storing a second one.
    function requestId() {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }
    var attempt = null;
    // An edited form is another attempt: its create must not be answered with the earlier key.
    ['change', 'input'].forEach(function (type) {
        section.addEventListener(type, function () {
            attempt = null;
        });
    });

    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce };
    }

    fetch(config.toolsUrl, { credentials: 'same-origin', headers: headers() })
        .then(function (response) {
            return response.ok ? response.json() : null;
        })
        .then(function (data) {
            if (!data || !Array.isArray(data.tools)) {
                return;
            }
            render(data.tools.filter(function (tool) {
                return tool && typeof tool.name === 'string';
            }));
            form.setAttribute('data-easy-mcp-served-list', '1');
        })
        .catch(function () {});

    form.addEventListener('submit', function (event) {
        if ('1' !== form.getAttribute('data-easy-mcp-served-list')) {
            return; // The rendered list: admin-post.php checks it as it rendered it.
        }
        event.preventDefault();
        attempt = attempt || requestId();
        var body = { key_name: '', expiry_days: '', expiry_custom: '', key_tools: [], request_id: attempt };
        Array.prototype.forEach.call(form.elements, function (element) {
            if (!element.name || element.disabled) {
                return;
            }
            if ('key_tools[]' === element.name) {
                if (element.checked) {
                    body.key_tools.push(element.value);
                }
            } else if (Object.prototype.hasOwnProperty.call(body, element.name)) {
                body[element.name] = element.value;
            }
        });
        if (generate) {
            generate.disabled = true;
        }
        if (error) {
            error.hidden = true;
        }
        fetch(config.keysUrl, { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify(body) })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (result.ok && result.data && typeof result.data.redirect === 'string') {
                    window.location.assign(result.data.redirect);
                    return;
                }
                if (result.data && ('easy_mcp_ai_self_service_key_shown' === result.data.code || 'easy_mcp_ai_self_service_unconfirmed' === result.data.code)) {
                    // That attempt is over (its key's secret is gone, or it stopped before saying
                    // whether it made one): the next click is a new key.
                    attempt = null;
                }
                throw new Error(result.data && result.data.message ? result.data.message : config.failed);
            })
            .catch(function (failure) {
                if (error) {
                    error.textContent = failure && failure.message ? failure.message : config.failed;
                    error.hidden = false;
                }
                if (generate) {
                    generate.disabled = !toolInputs().length;
                }
            });
    });
}());

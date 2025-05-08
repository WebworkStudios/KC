<?php
/**
 * Login-Formular View
 *
 * Zeigt ein Login-Formular mit Fehlermeldungen und CSRF-Schutz an.
 *
 * @var string $title Seitentitel
 * @var object|string $csrfToken CSRF-Token für das Formular
 * @var string|null $error Allgemeine Fehlermeldung
 * @var string|null $success Erfolgsmeldung
 * @var string|null $email_error Fehlermeldung für das E-Mail-Feld
 * @var string|null $password_error Fehlermeldung für das Passwort-Feld
 * @var string $old_email Vorher eingegebene E-Mail-Adresse
 */
?>

{% extends 'layouts/main' %}

{% section 'title' %}
{{ $title }}
{% endsection %}

{% section 'content' %}
<div class="auth-container">
    <div class="auth-card">
        <h1 class="auth-title">{{ $title }}</h1>

        {% if $success %}
        <div class="alert alert-success">
            {{ $success }}
        </div>
        {% endif %}

        {% if $error %}
        <div class="alert alert-danger">
            {{ $error }}
        </div>
        {% endif %}

        <form action="{{ url('auth.login.process') }}" method="post" class="auth-form">
            {% if $csrfToken %}
            {% if is_object($csrfToken) && method_exists($csrfToken, 'getValue') %}
            <input type="hidden" name="_csrf" value="{{ $csrfToken->getValue() }}">
            {% else %}
            <input type="hidden" name="_csrf" value="{{ $csrfToken }}">
            {% endif %}
            {% endif %}

            <div class="form-group">
                <label for="email">E-Mail-Adresse</label>
                <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control {{ $email_error ? 'is-invalid' : '' }}"
                        value="{{ $old_email }}"
                        required
                        autocomplete="email"
                >
                {% if $email_error %}
                <div class="invalid-feedback">
                    {{ $email_error }}
                </div>
                {% endif %}
            </div>

            <div class="form-group">
                <label for="password">Passwort</label>
                <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control {{ $password_error ? 'is-invalid' : '' }}"
                        required
                        autocomplete="current-password"
                >
                {% if $password_error %}
                <div class="invalid-feedback">
                    {{ $password_error }}
                </div>
                {% endif %}
            </div>

            <div class="form-group form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                <label class="form-check-label" for="remember">Angemeldet bleiben</label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Anmelden</button>
            </div>
        </form>

        <div class="auth-links">
            <a href="{{ url('auth.password.request') }}">Passwort vergessen?</a>
            <a href="{{ url('auth.register') }}">Noch kein Konto? Jetzt registrieren</a>
        </div>
    </div>
</div>
{% endsection %}

{% section 'scripts' %}
<script>
    // Fokus auf das erste fehlerhafte Feld oder auf die E-Mail setzen
    document.addEventListener('DOMContentLoaded', function() {
        const invalidField = document.querySelector('.is-invalid');
        if (invalidField) {
            invalidField.focus();
        } else {
            document.getElementById('email').focus();
        }
    });
</script>
{% endsection %}
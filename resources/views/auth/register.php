<?php
/**
 * Register Page Template
 */
?>

{% extends 'layouts/auth' %}

{% section 'title' %}
{{ title }} | KickersCup
{% endsection %}

{% section 'content' %}
<div class="auth-container">
    <div class="auth-box">
        <h1 class="auth-title">Registrieren</h1>

        {% if error %}
        <div class="alert alert-danger">
            {{ error }}
        </div>
        {% endif %}

        {% if success %}
        <div class="alert alert-success">
            {{ success }}
        </div>
        {% endif %}

        <form action="{{ url('auth.register.process') }}" method="post" class="auth-form">
            {!! csrfTokenField !!}

            <div class="form-group">
                <label for="username">Benutzername</label>
                <input type="text" name="username" id="username" class="form-control {% if username_error %}is-invalid{% endif %}" value="{{ old_username }}" required>
                {% if username_error %}
                <div class="invalid-feedback">{{ username_error }}</div>
                {% endif %}
            </div>

            <div class="form-group">
                <label for="email">E-Mail</label>
                <input type="email" name="email" id="email" class="form-control {% if email_error %}is-invalid{% endif %}" value="{{ old_email }}" required>
                {% if email_error %}
                <div class="invalid-feedback">{{ email_error }}</div>
                {% endif %}
            </div>

            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" name="password" id="password" class="form-control {% if password_error %}is-invalid{% endif %}" required>
                {% if password_error %}
                <div class="invalid-feedback">{{ password_error }}</div>
                {% endif %}
                <small class="form-text text-muted">Mindestens 8 Zeichen</small>
            </div>

            <div class="form-group">
                <label for="password_confirm">Passwort bestätigen</label>
                <input type="password" name="password_confirm" id="password_confirm" class="form-control {% if password_confirm_error %}is-invalid{% endif %}" required>
                {% if password_confirm_error %}
                <div class="invalid-feedback">{{ password_confirm_error }}</div>
                {% endif %}
            </div>

            <div class="form-group form-check">
                <input type="checkbox" name="terms_accepted" id="terms_accepted" class="form-check-input {% if terms_accepted_error %}is-invalid{% endif %}" required>
                <label class="form-check-label" for="terms_accepted">
                    Ich akzeptiere die <a href="{{ url('terms') }}" target="_blank">AGB</a> und <a href="{{ url('privacy') }}" target="_blank">Datenschutzbestimmungen</a>
                </label>
                {% if terms_accepted_error %}
                <div class="invalid-feedback">{{ terms_accepted_error }}</div>
                {% endif %}
            </div>

            <div class="form-group form-check">
                <input type="checkbox" name="newsletter" id="newsletter" class="form-check-input" {% if old_newsletter %}checked{% endif %}>
                <label class="form-check-label" for="newsletter">
                    Newsletter abonnieren
                </label>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-block">Registrieren</button>
            </div>

            <div class="auth-links">
                <p>Bereits registriert? <a href="{{ url('auth.login') }}">Anmelden</a></p>
            </div>
        </form>
    </div>
</div>
{% endsection %}
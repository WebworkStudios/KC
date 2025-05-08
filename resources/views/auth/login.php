<?php
// auth/login.php
/**
 * Login Template
 *
 * This template displays the login form for user authentication.
 */
?>

{% extends 'layouts/main' %}

{% section 'title' %}
{{ title }}
{% endsection %}

{% section 'content' %}
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Anmelden</div>
                <div class="card-body">
                    {% if success %}
                    <div class="alert alert-success">
                        {{ success }}
                    </div>
                    {% endif %}

                    {% if error %}
                    <div class="alert alert-danger">
                        {{ error }}
                    </div>
                    {% endif %}

                    <form method="POST" action="{{ url('auth.login.process') }}">
                        {!! csrfTokenField !!}

                        <div class="mb-3">
                            <label for="email" class="form-label">E-Mail-Adresse</label>
                            <input type="email" class="form-control {% if email_error %}is-invalid{% endif %}"
                                   id="email" name="email" value="{{ old_email }}" required autofocus>
                            {% if email_error %}
                            <div class="invalid-feedback">
                                {{ email_error }}
                            </div>
                            {% endif %}
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Passwort</label>
                            <input type="password" class="form-control {% if password_error %}is-invalid{% endif %}"
                                   id="password" name="password" required>
                            {% if password_error %}
                            <div class="invalid-feedback">
                                {{ password_error }}
                            </div>
                            {% endif %}
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Angemeldet bleiben</label>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Anmelden</button>
                        </div>
                    </form>

                    <div class="mt-3 text-center">
                        <a href="{{ url('auth.password.request') }}">Passwort vergessen?</a>
                        <p class="mt-3">Noch kein Konto? <a href="{{ url('auth.register') }}">Registrieren</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
{% endsection %}
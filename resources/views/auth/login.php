{% extends 'layouts/main' %}

{% section 'title' %}
{{ title }}
{% endsection %}

{% section 'content' %}
<div class="auth-container">
    <div class="auth-box">
        <h1 class="auth-title">Anmelden</h1>

        {% if error %}
        <div class="alert alert-danger">
            {{ error }}
        </div>
        {% endif %}

        <form method="POST" action="{{ url('auth.login.process') }}" class="auth-form">
            {!! csrfToken.generateTokenField('login_form') !!}

            <div class="form-group">
                <label for="email">E-Mail Adresse</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="{{ old_email }}" required autofocus>

                {% if email_error %}
                <div class="invalid-feedback d-block">
                    {{ email_error }}
                </div>
                {% endif %}
            </div>

            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" class="form-control" required>

                {% if password_error %}
                <div class="invalid-feedback d-block">
                    {{ password_error }}
                </div>
                {% endif %}
            </div>

            <div class="form-group form-check">
                <input type="checkbox" id="remember" name="remember" class="form-check-input" value="1">
                <label for="remember" class="form-check-label">Angemeldet bleiben</label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Anmelden</button>
            </div>

            <div class="auth-links">
                <a href="{{ url('auth.register') }}">Noch kein Konto? Registrieren</a>
            </div>
        </form>
    </div>
</div>
{% endsection %}
{# Template für die Anzeige der Spielerliste #}

{# Erweitere ein Layout, falls vorhanden #}
{% extends 'layouts/main' %}

{# Definiere den Titel-Bereich #}
{% section 'title' %}
{{ title ?? 'Spielerliste' }}
{% endsection %}

{# Definiere den Inhalts-Bereich #}
{% section 'content' %}
<div class="container mt-4">
    <h1>{{ title ?? 'Spielerliste' }}</h1>

    {% if error %}
    <div class="alert alert-danger">
        <strong>Fehler:</strong> {{ error }}
    </div>
    {% endif %}

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Alle Spieler ({{ playersCount }})</span>
            <a href="{{ url('player.create') }}" class="btn btn-sm btn-primary">Neuer Spieler</a>
        </div>

        <div class="card-body">
            {% if players is not null and players|length > 0 %}
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Vorname</th>
                        <th>Nachname</th>
                        <th>Erstellt am</th>
                        <th>Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    {% foreach players as player %}
                    <tr>
                        <td>{{ player.player_id }}</td>
                        <td>{{ player.first_name }}</td>
                        <td>{{ player.last_name }}</td>
                        <td>{{ dateFormat(player.created_date, 'd.m.Y H:i') }}</td>
                        <td>
                            <div class="btn-group">
                                <a href="{{ url('player.show', {'id': player.player_id}) }}"
                                   class="btn btn-sm btn-info">
                                    Anzeigen
                                </a>
                                <a href="{{ url('player.edit', {'id': player.player_id}) }}"
                                   class="btn btn-sm btn-warning">
                                    Bearbeiten
                                </a>
                            </div>
                        </td>
                    </tr>
                    {% endforeach %}
                    </tbody>
                </table>
            </div>
            {% else %}
            <div class="alert alert-info">
                Keine Spieler gefunden.
            </div>
            {% endif %}
        </div>
    </div>
</div>
{% endsection %}
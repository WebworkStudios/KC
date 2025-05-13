{% extends 'layouts/main' %}

{% section 'title' %}
{{ title }}
{% endsection %}

{% section 'content' %}
<div class="container py-4">
    <h1>{{ title }}</h1>

    {% if error %}
    <div class="alert alert-danger">
        <strong>Fehler:</strong> {{ error }}
    </div>
    {% endif %}

    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <span>Insgesamt {{ playersCount }} Spieler</span>
                <a href="{{ url('player.create') }}" class="btn btn-sm btn-primary">Neuer Spieler</a>
            </div>
        </div>
        <div class="card-body">
            {% if players|length > 0 %}
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
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
                        <td>{{ dateFormat(player.created_date) }}</td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="{{ url('player.show', {id: player.player_id}) }}" class="btn btn-info">
                                    Anzeigen
                                </a>
                                <a href="{{ url('player.edit', {id: player.player_id}) }}" class="btn btn-warning">
                                    Bearbeiten
                                </a>
                                <button type="button" class="btn btn-danger"
                                        onclick="confirmDelete({{ player.player_id }}, '{{ player.first_name }} {{ player.last_name }}')">
                                    Löschen
                                </button>
                            </div>
                        </td>
                    </tr>
                    {% endforeach %}
                    </tbody>
                </table>
            </div>
            {% else %}
            <div class="alert alert-info">
                Keine Spieler gefunden. Erstellen Sie einen neuen Spieler, um loszulegen.
            </div>
            {% endif %}
        </div>
    </div>
</div>

<!-- Lösch-Bestätigungsdialog -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Spieler löschen</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Schließen">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Möchten Sie den Spieler <span id="playerName"></span> wirklich löschen?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                <form id="deleteForm" method="POST" action="">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="btn btn-danger">Löschen</button>
                </form>
            </div>
        </div>
    </div>
</div>

{% php %}
// JavaScript für die Löschbestätigung
echo '<script>
    function confirmDelete(id, name) {
        document.getElementById("playerName").textContent = name;
        document.getElementById("deleteForm").action = "' . $this->url("player.delete") . '/" + id;
        $("#deleteModal").modal("show");
    }
</script>';
{% endphp %}
{% endsection %}
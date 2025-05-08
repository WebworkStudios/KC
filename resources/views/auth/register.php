<?= $this->extends('layouts/main') ?>

<?= $this->section('content') ?>
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">Registrieren</div>
                    <div class="card-body">
                        <?php if ($session->hasFlash('error')): ?>
                            <div class="alert alert-danger">
                                <?= $session->getFlash('error') ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="/register">
                            <!-- CSRF-Token -->
                            <input type="hidden" name="_csrf" value="<?= $csrfToken->getValue() ?>">

                            <div class="mb-3">
                                <label for="username" class="form-label">Benutzername</label>
                                <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                                       id="username" name="username" value="<?= $old['username'] ?? '' ?>">
                                <?php if (isset($errors['username'])): ?>
                                    <div class="invalid-feedback">
                                        <?= $errors['username'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">E-Mail-Adresse</label>
                                <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                                       id="email" name="email" value="<?= $old['email'] ?? '' ?>">
                                <?php if (isset($errors['email'])): ?>
                                    <div class="invalid-feedback">
                                        <?= $errors['email'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Passwort</label>
                                <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                                       id="password" name="password">
                                <?php if (isset($errors['password'])): ?>
                                    <div class="invalid-feedback">
                                        <?= $errors['password'] ?>
                                    </div>
                                <?php endif; ?>
                                <div class="form-text">Das Passwort muss mindestens 8 Zeichen lang sein.</div>
                            </div>

                            <div class="mb-3">
                                <label for="password_confirm" class="form-label">Passwort bestätigen</label>
                                <input type="password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                                       id="password_confirm" name="password_confirm">
                                <?php if (isset($errors['password_confirm'])): ?>
                                    <div class="invalid-feedback">
                                        <?= $errors['password_confirm'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input <?= isset($errors['terms_accepted']) ? 'is-invalid' : '' ?>"
                                       id="terms_accepted" name="terms_accepted" value="1" <?= ($old['terms_accepted'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="terms_accepted">
                                    Ich akzeptiere die <a href="/terms" target="_blank">AGB</a> und <a href="/privacy" target="_blank">Datenschutzbestimmungen</a>
                                </label>
                                <?php if (isset($errors['terms_accepted'])): ?>
                                    <div class="invalid-feedback">
                                        <?= $errors['terms_accepted'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="newsletter" name="newsletter" value="1" <?= ($old['newsletter'] ?? true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="newsletter">
                                    Ich möchte über Neuigkeiten per E-Mail informiert werden
                                </label>
                            </div>

                            <button type="submit" class="btn btn-primary">Registrieren</button>
                        </form>

                        <hr>

                        <div class="text-center">
                            <p>Bereits registriert? <a href="/login">Hier anmelden</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
<?php
<?= $this->extends('layouts/main') ?>

<?= $this->section('content') ?>
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">Anmelden</div>
                    <div class="card-body">
                        <?php if ($session->hasFlash('error')): ?>
                            <div class="alert alert-danger">
                                <?= $session->getFlash('error') ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($session->hasFlash('success')): ?>
                            <div class="alert alert-success">
                                <?= $session->getFlash('success') ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="/login">
                            <!-- CSRF-Token -->
                            <input type="hidden" name="_csrf" value="<?= $csrfToken->getValue() ?>">

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
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                                <label class="form-check-label" for="remember">Angemeldet bleiben</label>
                            </div>

                            <button type="submit" class="btn btn-primary">Anmelden</button>
                        </form>

                        <div class="mt-3">
                            <a href="/password/forgot">Passwort vergessen?</a>
                        </div>

                        <hr>

                        <div class="text-center">
                            <p>Noch kein Konto? <a href="/register">Jetzt registrieren</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
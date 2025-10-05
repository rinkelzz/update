# GitHub Update Script

Dieses Projekt enthält ein einzelnes PHP-Script (`update.php`), das direkt im Webroot Ihres Servers abgelegt werden kann. Es ermöglicht Ihnen, einen GitHub-Owner, ein Repository und einen Branch zu wählen und die Dateien des Branches in ein Zielverzeichnis zu übernehmen. Optional kann vor dem Update ein ZIP-Backup erstellt werden.

## Voraussetzungen
- PHP 7.4 oder neuer mit den Erweiterungen `curl`, `zip` und `json` (PHP 8.x wird empfohlen)
- Schreibrechte im Zielverzeichnis sowie im Verzeichnis, in dem `update.php` liegt (für temporäre Dateien und Konfiguration)
- Ausgehender HTTP-Zugriff auf `api.github.com` und `codeload.github.com`

## Installation
1. Legen Sie die Dateien `update.php` und `update.config.php` im gewünschten Verzeichnis Ihres Webservers ab (z. B. `/var/www/html`).
2. Stellen Sie sicher, dass die Dateien vom Webserver gelesen werden können und das Verzeichnis Schreibrechte für den Webserver-Benutzer besitzt.

## Konfiguration
Im Auslieferungszustand enthält `update.config.php` Platzhalter für Owner, Repository und optionale Ausschlüsse sowie aktive Zugangsdaten mit dem Standard-Benutzer `admin` und dem Passwort `change-me`. Sie können die Werte entweder direkt in der Datei eintragen oder sie werden (bis auf die Zugangsdaten) automatisch nach einer erfolgreichen Aktion über das Formular gespeichert.

```php
<?php
return [
    'owner' => 'Ihr-GitHub-Name',
    'repository' => 'Ihr-Repository-Name',
    'excludes' => [
        'config.php',
        'storage/',
    ],
    'auth' => [
        'username' => 'admin',
        'password_hash' => '$2y$12$v1OUgsjnzQ7o3vrZCMSxteopMaWbIoB5KGt7HlPgQuqIuMdKHo2Y2',
    ],
];
```

> **Hinweis:** Die Datei muss für den Webserver-Benutzer beschreibbar sein, damit die Werte automatisch aktualisiert werden können.

**Absicherung:** HTTP Basic Auth ist standardmäßig aktiv und verwendet den Benutzer `admin` mit dem Passwort `change-me`. Das Passwort wird als Bcrypt-Hash (`password_hash(..., PASSWORD_DEFAULT)`) dauerhaft in `update.config.php` gespeichert. Ändern Sie den Benutzer oder den Hash direkt in der Datei und erzeugen Sie neue Werte bei Bedarf mit `php -r "echo password_hash('IhrPasswort', PASSWORD_DEFAULT);"`. Sie können auch jeden anderen Generator verwenden, der kompatible Bcrypt-Hashes erzeugt. Weitere Schlüssel in der Konfiguration bleiben beim Speichern erhalten, sodass die Zugangsdaten nicht überschrieben werden.

**Ausschlüsse:** Jeder Eintrag in `excludes` wird relativ zur Projektwurzel interpretiert. Dateien geben Sie einfach mit Dateinamen an (z. B. `config.php`), Ordner mit abschließendem Slash (z. B. `storage/`). Diese Pfade werden beim Update nicht überschrieben.

## Bedienung
1. Öffnen Sie `update.php` im Browser.
2. Geben Sie GitHub-Owner und Repository an und klicken Sie auf **„Branches laden“**.
3. Wählen Sie im zweiten Schritt den gewünschten Branch aus der Liste. Für jeden Branch werden Erstellungs- und Aktualisierungszeit (falls abweichend), der letzte Commit sowie ein kurzer Commit-Hash angezeigt; die Sortierung erfolgt weiterhin nach dem jüngsten Commit.
4. Tragen Sie das Zielverzeichnis ein, in dem die Dateien aktualisiert werden sollen.
5. Optional: Geben Sie im Feld **„Pfade vom Update ausschließen“** Dateien oder Ordner (ein Eintrag pro Zeile) an, die nicht überschrieben werden sollen.
6. Optional: Aktivieren Sie die Checkbox „Vor dem Update ein ZIP-Backup anlegen“, um einen Sicherungssatz im Zielverzeichnis zu erstellen.
7. Klicken Sie auf **„Branch herunterladen und aktualisieren“**. Das Script lädt den Branch als ZIP-Datei, legt optional ein Backup an und überschreibt anschließend die Dateien im Zielverzeichnis. Ausgeschlossene Pfade werden übersprungen.

Während des Ablaufs werden Statusmeldungen sowie Fehlerhinweise oberhalb des Formulars eingeblendet.

## Tipps
- Testen Sie den Ablauf zunächst in einer Staging- oder Testumgebung.
- Bewahren Sie mehrere Backups auf, falls Sie zu einer früheren Version zurückkehren müssen.
- Halten Sie Ihre PHP-Version sowie die benötigten Erweiterungen aktuell, um Kompatibilitätsprobleme zu vermeiden.

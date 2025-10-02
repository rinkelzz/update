# GitHub Update Script

Dieses Projekt enthält ein einzelnes PHP-Script (`update.php`), das direkt im Webroot Ihres Servers abgelegt werden kann. Es ermöglicht Ihnen, einen GitHub-Owner, ein Repository und einen Branch zu wählen und die Dateien des Branches in ein Zielverzeichnis zu übernehmen. Optional kann vor dem Update ein ZIP-Backup erstellt werden.

## Voraussetzungen
- PHP 8.1 oder neuer mit den Erweiterungen `curl`, `zip` und `json`
- Schreibrechte im Zielverzeichnis sowie im Verzeichnis, in dem `update.php` liegt (für temporäre Dateien und Konfiguration)
- Ausgehender HTTP-Zugriff auf `api.github.com` und `codeload.github.com`

## Installation
1. Legen Sie die Dateien `update.php` und `update.config.php` im gewünschten Verzeichnis Ihres Webservers ab (z. B. `/var/www/html`).
2. Stellen Sie sicher, dass die Dateien vom Webserver gelesen werden können und das Verzeichnis Schreibrechte für den Webserver-Benutzer besitzt.

## Konfiguration
Im Auslieferungszustand enthält `update.config.php` nur leere Platzhalter für Owner, Repository und optionale Ausschlüsse. Sie können die Werte entweder direkt in der Datei eintragen oder sie werden automatisch nach einer erfolgreichen Aktion über das Formular gespeichert.

```php
<?php
return [
    'owner' => 'Ihr-GitHub-Name',
    'repository' => 'Ihr-Repository-Name',
    'excludes' => [
        'config.php',
        'storage/',
    ],
];
```

> **Hinweis:** Die Datei muss für den Webserver-Benutzer beschreibbar sein, damit die Werte automatisch aktualisiert werden können.

**Ausschlüsse:** Jeder Eintrag in `excludes` wird relativ zur Projektwurzel interpretiert. Dateien geben Sie einfach mit Dateinamen an (z. B. `config.php`), Ordner mit abschließendem Slash (z. B. `storage/`). Diese Pfade werden beim Update nicht überschrieben.

## Bedienung
1. Öffnen Sie `update.php` im Browser.
2. Geben Sie GitHub-Owner und Repository an und klicken Sie auf **„Branches laden“**.
3. Wählen Sie im zweiten Schritt den gewünschten Branch aus.
4. Tragen Sie das Zielverzeichnis ein, in dem die Dateien aktualisiert werden sollen.
5. Optional: Geben Sie im Feld **„Pfade vom Update ausschließen“** Dateien oder Ordner (ein Eintrag pro Zeile) an, die nicht überschrieben werden sollen.
6. Optional: Aktivieren Sie die Checkbox „Vor dem Update ein ZIP-Backup anlegen“, um einen Sicherungssatz im Zielverzeichnis zu erstellen.
7. Klicken Sie auf **„Branch herunterladen und aktualisieren“**. Das Script lädt den Branch als ZIP-Datei, legt optional ein Backup an und überschreibt anschließend die Dateien im Zielverzeichnis. Ausgeschlossene Pfade werden übersprungen.

Während des Ablaufs werden Statusmeldungen sowie Fehlerhinweise oberhalb des Formulars eingeblendet.

## Tipps
- Testen Sie den Ablauf zunächst in einer Staging- oder Testumgebung.
- Bewahren Sie mehrere Backups auf, falls Sie zu einer früheren Version zurückkehren müssen.
- Halten Sie Ihre PHP-Version sowie die benötigten Erweiterungen aktuell, um Kompatibilitätsprobleme zu vermeiden.

# ECOPRIM — Application de gestion d'école primaire

Squelette de projet : **Laravel (API)** + **React/Vite/Tailwind** + **SQL Server**.

## Structure

```
ECOPRIM/
├── backend/    → API Laravel (à installer avec composer)
└── frontend/   → SPA React + Vite + Tailwind (déjà installé)
```

## 1. Installation du backend (Laravel)

Prérequis : PHP 8.2+, Composer, extensions `sqlsrv` et `pdo_sqlsrv` activées, une instance SQL Server accessible.

```bash
cd backend
composer install
copy .env.example .env        # (ou cp sur Linux/Mac)
php artisan key:generate
```

Configure ensuite tes identifiants SQL Server dans le fichier `.env` (section DB_*), puis :

```bash
php artisan migrate
php artisan serve              # démarre l'API sur http://localhost:8000
```

## 2. Installation du frontend (React + Vite + Tailwind)

Le frontend est déjà initialisé avec ses dépendances (Vite, Tailwind v4, React Router, TanStack Query, Zustand, React Hook Form, Zod, Axios, Recharts, Lucide).

```bash
cd frontend
copy .env.example .env
npm install          # si besoin de réinstaller les node_modules
npm run dev           # démarre le frontend sur http://localhost:5173
```

## 3. Prochaines étapes

- [ ] Compléter les migrations (classes, notes, absences, paiements...)
- [ ] Implémenter l'authentification Sanctum (login/logout, middleware de rôle)
- [ ] Développer les CRUD élèves/classes/notes
- [ ] Construire les écrans UI (dashboard, fiche élève, saisie des notes...)

Voir le document `roadmap-gestion-ecole-primaire.md` fourni précédemment pour le détail complet de la feuille de route.

import {
  BarChart3,
  BookOpen,
  Building2,
  ClipboardList,
  Megaphone,
  ShieldCheck,
  SlidersHorizontal,
} from 'lucide-react'

// Données de navigation de l'application NEXORA (hors console admin et portails,
// qui ont leurs propres menus). Séparées du composant : l'en-tête de page a
// besoin de la même carte des modules que le menu, et les dupliquer les aurait
// fait diverger au premier ajout de rubrique.

// Navigation NEXORA. Seules les pages réellement fonctionnelles sont listées ; les
// entrées non construites ont été retirées. (Le mécanisme « Bientôt » — une entrée
// `to: null` — reste disponible, mais plus aucune ne l'utilise.)
// Le groupe « Parents / Tuteurs » a été retiré : son annuaire n'était qu'une relecture
// des fiches élèves, où ces coordonnées se lisent et se corrigent déjà ; et son entrée
// « Espace parent » annonçait un portail qui existe désormais pour de bon (/mon-espace).
// Les pages adossées à des tables inexistantes (documents/annonces/messages) ont été
// supprimées ; Communication passe par l'envoi SMS/Mail.
// Le groupe « Affectation » a été retiré : sa seule entrée renvoyait vers /classes, déjà
// listée sous Paramètre, et l'affectation enseignant-classe a désormais sa vraie page.
// Cours, Ressources, Conseils et Sanctions ont été retirés : ils reposaient sur le
// schéma local d'avant le pivot et répondaient 500. Le Cahier journal, lui, a été
// reconstruit sur ECONOMAT.T_ENTETE_JOURNAL / T_CAHIER_JOURNAL.
//
// Accent de module : un repère, pas une décoration. Il ne touche que le titre du
// groupe et un filet vertical le long de ses entrées ; les surfaces, boutons et
// badges restent bleus ou neutres. Sans cette retenue, le vert « module
// Traitement » se confondrait avec le vert « validé », et l'orange « module
// Programme » avec l'orange « à traiter » — les deux sens coexistent dans la
// palette, seule la surface d'application les distingue.
export const GROUPS = [
  {
    label: 'Paramètre',
    Icone: SlidersHorizontal,
    module: 'parametre',
    items: [
      { to: '/annees-scolaires', label: 'Années scolaires' },
      { to: '/niveaux', label: 'Niveaux' },
      { to: '/classes', label: 'Classes' },
      { to: '/matieres', label: 'Matières' },
      { to: '/parametres/documents-eleves', label: 'Documents élèves' },
      { to: '/parametres/sms', label: 'Passerelle SMS' },
      { to: '/parametres/mail', label: 'Messagerie (SMTP)' },
      { to: '/affectations-enseignants', label: 'Affectation enseignant – classe' },
    ],
  },
  {
    label: 'Traitement',
    Icone: ClipboardList,
    module: 'traitement',
    items: [
      { to: '/inscriptions', label: 'Élèves et inscriptions' },
      { to: '/enseignants', label: 'Enseignants' },
      { to: '/absences', label: 'Absences' },
    ],
  },
  {
    label: 'Programme',
    Icone: BookOpen,
    module: 'programme',
    items: [
      { to: '/emplois-du-temps', label: 'Emplois du temps' },
      { to: '/cahier-journal', label: 'Cahier journal' },
      { to: '/devoirs', label: 'Devoirs' },
      { to: '/calendrier-scolaire', label: 'Calendrier scolaire' },
    ],
  },
  {
    label: 'Évaluation & Résultats',
    Icone: BarChart3,
    // Vert : notes et résultats relèvent de l'opération quotidienne, pas de la
    // planification pédagogique.
    module: 'traitement',
    items: [
      { to: '/evaluations', label: 'Évaluations' },
      // La saisie revient à l'enseignant, depuis sa classe (portail). La direction consulte :
      // on ne lui propose pas un écran qui refuserait d'enregistrer.
      { to: '/notes', label: 'Saisie des notes', permission: 'saisir_notes' },
      { to: '/notes/consultation', label: 'Consultation des notes' },
      { to: '/moyennes', label: 'Résultats & bulletins' },
      { to: '/assiduite', label: 'Assiduité' },
    ],
  },
  {
    label: 'Communication',
    Icone: Megaphone,
    // Orange : notifications et envois appellent une action.
    module: 'programme',
    items: [
      { to: '/communication/envoi', label: 'Envoi SMS / Mail' },
      { to: '/communication/historique', label: 'Historique des envois' },
    ],
  },
  {
    label: 'Administration',
    Icone: Building2,
    module: 'parametre',
    superAdminOnly: true,
    items: [
      { to: '/admin/societes', label: 'Sociétés' },
      { to: '/admin/etablissements', label: 'Établissements' },
    ],
  },
  // Le quotidien d'un Directeur / Admin Établissement : gérer les comptes de son
  // établissement, leurs rôles et ce que chaque rôle a le droit de faire. Ouvert dès que
  // peutConsole est vrai (Super Admin, Admin Société ou Admin Établissement) — pas
  // superAdminOnly comme le groupe ci-dessus, qui reste la console générale/société.
  {
    label: 'Configuration administrative',
    Icone: ShieldCheck,
    module: 'parametre',
    peutConsoleOnly: true,
    items: [
      { to: '/admin/utilisateurs', label: 'Utilisateurs' },
      { to: '/admin/roles', label: 'Rôles' },
      { to: '/admin/permissions', label: 'Permissions' },
    ],
  },
]

/**
 * Le menu est sombre dans les deux thèmes — bleu nuit en clair, ardoise en
 * sombre. Ses accents ne peuvent donc pas être ceux de l'en-tête de page, qui
 * repose lui sur une surface claire : les mêmes nuances y seraient illisibles
 * d'un côté ou de l'autre. D'où deux jeux de variables pour un même module.
 */
export const couleurNav = (module) => (module ? `var(--module-${module}-nav)` : null)

/**
 * Retrouve la couleur du module auquel appartient un chemin. Sert à l'en-tête de
 * page, qui reprend l'accent du module où l'on se trouve : sans ce rappel,
 * l'indice disparaîtrait dès que le menu est replié ou fermé en tiroir.
 */
export function couleurModulePourChemin(pathname) {
  // Correspondance la plus longue : '/notes/consultation' ne doit pas être
  // captée par une entrée '/notes' qui relèverait d'un autre groupe.
  let trouve = null
  let longueur = -1
  for (const group of GROUPS) {
    for (const item of group.items) {
      if (!item.to || item.to === '/') continue
      const correspond = pathname === item.to || pathname.startsWith(`${item.to}/`)
      if (correspond && item.to.length > longueur) {
        longueur = item.to.length
        trouve = group.module
      }
    }
  }
  return trouve ? `var(--module-${trouve})` : null
}

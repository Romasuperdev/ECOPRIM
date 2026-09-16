import { useEffect, useState, lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import ProtectedRoute from './routes/ProtectedRoute'
import Layout from './components/layout/Layout'
import AdminLayout from './components/layout/AdminLayout'
import PortailLayout from './components/layout/PortailLayout'
import { useAuthStore } from './store/authStore'
import { fetchMe } from './features/auth/authApi'

// Pages chargées à la demande (code-splitting par route) pour alléger le premier chargement.
const LoginPage = lazy(() => import('./features/auth/LoginPage'))
const DashboardPage = lazy(() => import('./features/dashboard/DashboardPage'))
const EleveListPage = lazy(() => import('./features/eleves/EleveListPage'))
const EleveDetailPage = lazy(() => import('./features/eleves/EleveDetailPage'))
const ClasseListPage = lazy(() => import('./features/classes/ClasseListPage'))
const EnseignantListPage = lazy(() => import('./features/enseignants/EnseignantListPage'))
const EnseignantFormPage = lazy(() => import('./features/enseignants/EnseignantFormPage'))
const MatiereListPage = lazy(() => import('./features/matieres/MatiereListPage'))
const MatiereFormPage = lazy(() => import('./features/matieres/MatiereFormPage'))
const NoteListPage = lazy(() => import('./features/notes/NoteListPage'))
const SaisieNotesPage = lazy(() => import('./features/notes/SaisieNotesPage'))
const AbsenceListPage = lazy(() => import('./features/absences/AbsenceListPage'))
const NiveauListPage = lazy(() => import('./features/niveaux/NiveauListPage'))
const AnneeScolaireListPage = lazy(() => import('./features/annees-scolaires/AnneeScolaireListPage'))
const MoyennesPage = lazy(() => import('./features/rapports/MoyennesPage'))
const AssiduitePage = lazy(() => import('./features/rapports/AssiduitePage'))
const EvaluationListPage = lazy(() => import('./features/rapports/EvaluationListPage'))
const InscriptionListPage = lazy(() => import('./features/inscriptions/InscriptionListPage'))
const AdminDashboardPage = lazy(() => import('./features/admin/AdminDashboardPage'))
const SocieteListPage = lazy(() => import('./features/admin/SocieteListPage'))
const ChoisirEtablissementPage = lazy(() => import('./features/contexte/ChoisirEtablissementPage'))
const EmploiDuTempsPage = lazy(() => import('./features/emplois-du-temps/EmploiDuTempsPage'))
const AffectationEnseignantPage = lazy(() => import('./features/affectations-enseignants/AffectationEnseignantPage'))
const CahierTextesPage = lazy(() => import('./features/cahier-textes/CahierTextesPage'))
const DevoirListPage = lazy(() => import('./features/devoirs/DevoirListPage'))
const CalendrierScolairePage = lazy(() => import('./features/calendrier/CalendrierScolairePage'))
const DocumentsElevesParamPage = lazy(() => import('./features/parametres/DocumentsElevesPage'))
const SmsConfigPage = lazy(() => import('./features/parametres/SmsConfigPage'))
const MailConfigPage = lazy(() => import('./features/parametres/MailConfigPage'))
const EnvoiPage = lazy(() => import('./features/communication/EnvoiPage'))
const HistoriquePage = lazy(() => import('./features/communication/HistoriquePage'))
const RoleListPage = lazy(() => import('./features/admin/RoleListPage'))
const PermissionsPage = lazy(() => import('./features/admin/PermissionsPage'))
const TracabilitePage = lazy(() => import('./features/tracabilite/TracabilitePage'))
const EtablissementListPage = lazy(() => import('./features/admin/EtablissementListPage'))
const EtablissementDetailPage = lazy(() => import('./features/admin/EtablissementDetailPage'))
const UtilisateurListPage = lazy(() => import('./features/admin/UtilisateurListPage'))
const UtilisateurDetailPage = lazy(() => import('./features/admin/UtilisateurDetailPage'))
const EnseignantAccueilPage = lazy(() => import('./features/portail-enseignant/EnseignantAccueilPage'))
const EnseignantClassePage = lazy(() => import('./features/portail-enseignant/EnseignantClassePage'))
const ParentAccueilPage = lazy(() => import('./features/portail-parent/ParentAccueilPage'))
const ParentEnfantPage = lazy(() => import('./features/portail-parent/ParentEnfantPage'))

const queryClient = new QueryClient()

function PageLoader() {
  return <div className="flex min-h-[40vh] items-center justify-center text-slate-400">Chargement…</div>
}

// Accueil de /mon-espace : le portail dépend du type de compte, pas de la route.
function MonEspaceIndex() {
  const typePortail = useAuthStore((state) => state.typePortail)

  return typePortail === 'parent' ? <ParentAccueilPage /> : <EnseignantAccueilPage />
}

// Squelette de routage principal — à compléter au fur et à mesure des modules
export default function App() {
  const [checkingSession, setCheckingSession] = useState(true)
  const setUser = useAuthStore((state) => state.setUser)

  useEffect(() => {
    fetchMe()
      .then((user) => setUser(user))
      .catch(() => {})
      .finally(() => setCheckingSession(false))
  }, [setUser])

  if (checkingSession) {
    return <div className="flex min-h-screen items-center justify-center text-slate-400">Chargement…</div>
  }

  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Suspense fallback={<PageLoader />}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />

            <Route
              element={
                <ProtectedRoute exigeConsole>
                  <AdminLayout />
                </ProtectedRoute>
              }
            >
              <Route path="/admin" element={<AdminDashboardPage />} />
              {/* Console générale : réservée au Super Admin, côté écran comme côté serveur. */}
              <Route
                path="/admin/societes"
                element={<ProtectedRoute exigeSuperAdmin><SocieteListPage /></ProtectedRoute>}
              />
              {/* Établissements et traçabilité : niveau société — un Admin Établissement
                  n'y a pas accès. Utilisateurs & Accès et Rôles, en revanche, sont son
                  quotidien : créer ses comptes (Enseignant, Parent...) et les rôles propres
                  à sa société, sans dépendre d'un admin de société ou général. */}
              <Route
                path="/admin/etablissements"
                element={<ProtectedRoute exigeNiveauSociete><EtablissementListPage /></ProtectedRoute>}
              />
              <Route
                path="/admin/etablissements/:code"
                element={<ProtectedRoute exigeNiveauSociete><EtablissementDetailPage /></ProtectedRoute>}
              />
              {/* Traçabilité : la liste générale, et celle d'un compte depuis sa fiche. Reste
                  dans la console (niveau société) : Utilisateurs/Rôles/Permissions, eux, ont
                  déménagé dans l'application (voir plus bas, Configuration administrative) —
                  un Directeur n'a plus besoin de « sortir » vers la console pour son quotidien. */}
              <Route
                path="/admin/tracabilite"
                element={<ProtectedRoute exigeNiveauSociete><TracabilitePage /></ProtectedRoute>}
              />
              <Route
                path="/admin/utilisateurs/:id/tracabilite"
                element={<ProtectedRoute exigeNiveauSociete><TracabilitePage /></ProtectedRoute>}
              />
              </Route>

            {/* Portails restreints — un compte affecté du SEUL rôle Enseignant ou Parent
                (RhUser::typePortail()) n'atteint jamais l'application complète ci-dessous :
                ProtectedRoute l'y renvoie, et le serveur la refuserait de toute façon. */}
            <Route
              element={
                <ProtectedRoute portailAutorise={['enseignant', 'parent']}>
                  <PortailLayout />
                </ProtectedRoute>
              }
            >
              <Route path="/mon-espace" element={<MonEspaceIndex />} />
              <Route path="/mon-espace/classes/:classe" element={<EnseignantClassePage />} />
              <Route path="/mon-espace/enfants/:matricule" element={<ParentEnfantPage />} />
            </Route>

            <Route
              element={
                <ProtectedRoute portailAutorise={['staff']}>
                  <Layout />
                </ProtectedRoute>
              }
            >
              <Route path="/" element={<DashboardPage />} />
              <Route path="/choisir-etablissement" element={<ChoisirEtablissementPage />} />
              <Route path="/emplois-du-temps" element={<EmploiDuTempsPage />} />
              <Route path="/affectations-enseignants" element={<AffectationEnseignantPage />} />
              <Route path="/cahier-textes" element={<CahierTextesPage />} />
              <Route path="/devoirs" element={<DevoirListPage />} />
              <Route path="/calendrier-scolaire" element={<CalendrierScolairePage />} />

              {/* Configuration administrative — Utilisateurs, Rôles, Permissions : ouvert dès
                  que peutConsole est vrai (Super Admin, Admin Société, Admin Établissement),
                  mais SANS quitter l'application (pas de bascule vers AdminLayout/console) —
                  contrairement à Sociétés/Établissements/Traçabilité, qui restent la console. */}
              <Route
                path="/admin/utilisateurs"
                element={<ProtectedRoute exigeConsole><UtilisateurListPage /></ProtectedRoute>}
              />
              <Route
                path="/admin/utilisateurs/:id"
                element={<ProtectedRoute exigeConsole><UtilisateurDetailPage /></ProtectedRoute>}
              />
              <Route
                path="/admin/roles"
                element={<ProtectedRoute exigeConsole><RoleListPage /></ProtectedRoute>}
              />
              <Route
                path="/admin/permissions"
                element={<ProtectedRoute exigeConsole><PermissionsPage /></ProtectedRoute>}
              />

              <Route path="/parametres/documents-eleves" element={<DocumentsElevesParamPage />} />
              <Route path="/parametres/sms" element={<SmsConfigPage />} />
              <Route path="/parametres/mail" element={<MailConfigPage />} />

              <Route path="/communication/envoi" element={<EnvoiPage />} />
              <Route path="/communication/historique" element={<HistoriquePage />} />

              <Route path="/niveaux" element={<NiveauListPage />} />
              <Route path="/annees-scolaires" element={<AnneeScolaireListPage />} />

              <Route path="/eleves" element={<EleveListPage />} />
              <Route path="/eleves/:id" element={<EleveDetailPage />} />

              <Route path="/classes" element={<ClasseListPage />} />

              <Route path="/enseignants" element={<EnseignantListPage />} />
              <Route path="/enseignants/nouveau" element={<EnseignantFormPage />} />
              <Route path="/enseignants/:id/modifier" element={<EnseignantFormPage />} />

              <Route path="/matieres" element={<MatiereListPage />} />
              <Route path="/matieres/nouvelle" element={<MatiereFormPage />} />
              <Route path="/matieres/:id/modifier" element={<MatiereFormPage />} />

              {/* /notes est la SAISIE : c'est ce que le menu promet depuis le début.
                  La consultation d'ECONOMAT (V_NOTECLASSE) garde sa propre adresse. */}
              <Route path="/notes" element={<SaisieNotesPage />} />
              <Route path="/notes/consultation" element={<NoteListPage />} />

              <Route path="/absences" element={<AbsenceListPage />} />



              <Route path="/moyennes" element={<MoyennesPage />} />



              <Route path="/assiduite" element={<AssiduitePage />} />
              <Route path="/evaluations" element={<EvaluationListPage />} />


              <Route path="/inscriptions" element={<InscriptionListPage />} />
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

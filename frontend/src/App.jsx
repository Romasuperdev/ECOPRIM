import { useEffect, useState, lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import ProtectedRoute from './routes/ProtectedRoute'
import Layout from './components/layout/Layout'
import AdminLayout from './components/layout/AdminLayout'
import { useAuthStore } from './store/authStore'
import { ROLES } from './lib/constants'
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
const AbsenceListPage = lazy(() => import('./features/absences/AbsenceListPage'))
const ParentListPage = lazy(() => import('./features/parents/ParentListPage'))
const ParentFormPage = lazy(() => import('./features/parents/ParentFormPage'))
const SeanceListPage = lazy(() => import('./features/seances/SeanceListPage'))
const SeanceFormPage = lazy(() => import('./features/seances/SeanceFormPage'))
const NiveauListPage = lazy(() => import('./features/niveaux/NiveauListPage'))
const AnneeScolaireListPage = lazy(() => import('./features/annees-scolaires/AnneeScolaireListPage'))
const MoyennesPage = lazy(() => import('./features/rapports/MoyennesPage'))
const AssiduitePage = lazy(() => import('./features/rapports/AssiduitePage'))
const EvaluationListPage = lazy(() => import('./features/rapports/EvaluationListPage'))
const ProgrammeListPage = lazy(() => import('./features/programmes/ProgrammeListPage'))
const ProgrammeFormPage = lazy(() => import('./features/programmes/ProgrammeFormPage'))
const RessourceListPage = lazy(() => import('./features/ressources/RessourceListPage'))
const InscriptionListPage = lazy(() => import('./features/inscriptions/InscriptionListPage'))
const ConseilListPage = lazy(() => import('./features/conseils/ConseilListPage'))
const ConseilDetailPage = lazy(() => import('./features/conseils/ConseilDetailPage'))
const AdminDashboardPage = lazy(() => import('./features/admin/AdminDashboardPage'))
const SocieteListPage = lazy(() => import('./features/admin/SocieteListPage'))
const ChoisirEtablissementPage = lazy(() => import('./features/contexte/ChoisirEtablissementPage'))
const DocumentsElevesParamPage = lazy(() => import('./features/parametres/DocumentsElevesPage'))
const SmsConfigPage = lazy(() => import('./features/parametres/SmsConfigPage'))
const MailConfigPage = lazy(() => import('./features/parametres/MailConfigPage'))
const EnvoiPage = lazy(() => import('./features/communication/EnvoiPage'))
const HistoriquePage = lazy(() => import('./features/communication/HistoriquePage'))
const RoleListPage = lazy(() => import('./features/admin/RoleListPage'))
const EtablissementListPage = lazy(() => import('./features/admin/EtablissementListPage'))
const EtablissementDetailPage = lazy(() => import('./features/admin/EtablissementDetailPage'))
const UtilisateurListPage = lazy(() => import('./features/admin/UtilisateurListPage'))
const UtilisateurDetailPage = lazy(() => import('./features/admin/UtilisateurDetailPage'))

const queryClient = new QueryClient()

function PageLoader() {
  return <div className="flex min-h-[40vh] items-center justify-center text-slate-400">Chargement…</div>
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
                <ProtectedRoute allowedRoles={[ROLES.SUPER_ADMIN]}>
                  <AdminLayout />
                </ProtectedRoute>
              }
            >
              <Route path="/admin" element={<AdminDashboardPage />} />
              <Route path="/admin/societes" element={<SocieteListPage />} />
              <Route path="/admin/etablissements" element={<EtablissementListPage />} />
              <Route path="/admin/etablissements/:code" element={<EtablissementDetailPage />} />
              <Route path="/admin/utilisateurs" element={<UtilisateurListPage />} />
              <Route path="/admin/utilisateurs/:id" element={<UtilisateurDetailPage />} />
              <Route path="/admin/roles" element={<RoleListPage />} />
              </Route>

            <Route
              element={
                <ProtectedRoute>
                  <Layout />
                </ProtectedRoute>
              }
            >
              <Route path="/" element={<DashboardPage />} />
              <Route path="/choisir-etablissement" element={<ChoisirEtablissementPage />} />

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

              <Route path="/notes" element={<NoteListPage />} />

              <Route path="/absences" element={<AbsenceListPage />} />



              <Route path="/moyennes" element={<MoyennesPage />} />

              <Route path="/parents" element={<ParentListPage />} />
              <Route path="/parents/nouveau" element={<ParentFormPage />} />
              <Route path="/parents/:id/modifier" element={<ParentFormPage />} />

              <Route path="/seances" element={<SeanceListPage />} />
              <Route path="/seances/nouvelle" element={<SeanceFormPage />} />
              <Route path="/seances/:id/modifier" element={<SeanceFormPage />} />

              <Route path="/assiduite" element={<AssiduitePage />} />
              <Route path="/evaluations" element={<EvaluationListPage />} />

              <Route path="/programmes" element={<ProgrammeListPage />} />
              <Route path="/programmes/nouveau" element={<ProgrammeFormPage />} />
              <Route path="/programmes/:id/modifier" element={<ProgrammeFormPage />} />

              <Route path="/ressources" element={<RessourceListPage />} />


              <Route path="/inscriptions" element={<InscriptionListPage />} />


              <Route path="/conseils-classe" element={<ConseilListPage />} />
              <Route path="/conseils-classe/:id" element={<ConseilDetailPage />} />
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

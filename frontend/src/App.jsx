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
const EleveFormPage = lazy(() => import('./features/eleves/EleveFormPage'))
const ClasseListPage = lazy(() => import('./features/classes/ClasseListPage'))
const ClasseFormPage = lazy(() => import('./features/classes/ClasseFormPage'))
const EnseignantListPage = lazy(() => import('./features/enseignants/EnseignantListPage'))
const EnseignantFormPage = lazy(() => import('./features/enseignants/EnseignantFormPage'))
const MatiereListPage = lazy(() => import('./features/matieres/MatiereListPage'))
const MatiereFormPage = lazy(() => import('./features/matieres/MatiereFormPage'))
const NoteListPage = lazy(() => import('./features/notes/NoteListPage'))
const NoteFormPage = lazy(() => import('./features/notes/NoteFormPage'))
const AbsenceListPage = lazy(() => import('./features/absences/AbsenceListPage'))
const AbsenceFormPage = lazy(() => import('./features/absences/AbsenceFormPage'))
const SanctionListPage = lazy(() => import('./features/sanctions/SanctionListPage'))
const SanctionFormPage = lazy(() => import('./features/sanctions/SanctionFormPage'))
const ParentListPage = lazy(() => import('./features/parents/ParentListPage'))
const ParentFormPage = lazy(() => import('./features/parents/ParentFormPage'))
const SeanceListPage = lazy(() => import('./features/seances/SeanceListPage'))
const SeanceFormPage = lazy(() => import('./features/seances/SeanceFormPage'))
const NiveauListPage = lazy(() => import('./features/niveaux/NiveauListPage'))
const AnneeScolaireListPage = lazy(() => import('./features/annees-scolaires/AnneeScolaireListPage'))
const RetardListPage = lazy(() => import('./features/retards/RetardListPage'))
const RetardFormPage = lazy(() => import('./features/retards/RetardFormPage'))
const MoyennesPage = lazy(() => import('./features/rapports/MoyennesPage'))
const AssiduitePage = lazy(() => import('./features/rapports/AssiduitePage'))
const EvaluationListPage = lazy(() => import('./features/rapports/EvaluationListPage'))
const ProgrammeListPage = lazy(() => import('./features/programmes/ProgrammeListPage'))
const ProgrammeFormPage = lazy(() => import('./features/programmes/ProgrammeFormPage'))
const RessourceListPage = lazy(() => import('./features/ressources/RessourceListPage'))
const DocumentsElevesPage = lazy(() => import('./features/documents/DocumentsElevesPage'))
const DocumentsEnseignantsPage = lazy(() => import('./features/documents/DocumentsEnseignantsPage'))
const DocumentsEtablissementPage = lazy(() => import('./features/documents/DocumentsEtablissementPage'))
const InscriptionListPage = lazy(() => import('./features/inscriptions/InscriptionListPage'))
const MessagesPage = lazy(() => import('./features/communication/MessagesPage'))
const AnnoncesPage = lazy(() => import('./features/communication/AnnoncesPage'))
const ConseilListPage = lazy(() => import('./features/conseils/ConseilListPage'))
const ConseilDetailPage = lazy(() => import('./features/conseils/ConseilDetailPage'))
const AdminDashboardPage = lazy(() => import('./features/admin/AdminDashboardPage'))
const SocieteListPage = lazy(() => import('./features/admin/SocieteListPage'))
const EtablissementListPage = lazy(() => import('./features/admin/EtablissementListPage'))
const UtilisateurListPage = lazy(() => import('./features/admin/UtilisateurListPage'))
const UtilisateurDetailPage = lazy(() => import('./features/admin/UtilisateurDetailPage'))
const JournalActivitePage = lazy(() => import('./features/admin/JournalActivitePage'))

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
              <Route path="/admin/utilisateurs" element={<UtilisateurListPage />} />
              <Route path="/admin/utilisateurs/:id" element={<UtilisateurDetailPage />} />
              <Route path="/admin/journal-activite" element={<JournalActivitePage />} />
            </Route>

            <Route
              element={
                <ProtectedRoute>
                  <Layout />
                </ProtectedRoute>
              }
            >
              <Route path="/" element={<DashboardPage />} />

              <Route path="/niveaux" element={<NiveauListPage />} />
              <Route path="/annees-scolaires" element={<AnneeScolaireListPage />} />

              <Route path="/eleves" element={<EleveListPage />} />
              <Route path="/eleves/nouveau" element={<EleveFormPage />} />
              <Route path="/eleves/:id/modifier" element={<EleveFormPage />} />

              <Route path="/classes" element={<ClasseListPage />} />
              <Route path="/classes/nouvelle" element={<ClasseFormPage />} />
              <Route path="/classes/:id/modifier" element={<ClasseFormPage />} />

              <Route path="/enseignants" element={<EnseignantListPage />} />
              <Route path="/enseignants/nouveau" element={<EnseignantFormPage />} />
              <Route path="/enseignants/:id/modifier" element={<EnseignantFormPage />} />

              <Route path="/matieres" element={<MatiereListPage />} />
              <Route path="/matieres/nouvelle" element={<MatiereFormPage />} />
              <Route path="/matieres/:id/modifier" element={<MatiereFormPage />} />

              <Route path="/notes" element={<NoteListPage />} />
              <Route path="/notes/nouvelle" element={<NoteFormPage />} />
              <Route path="/notes/:id/modifier" element={<NoteFormPage />} />

              <Route path="/absences" element={<AbsenceListPage />} />
              <Route path="/absences/nouvelle" element={<AbsenceFormPage />} />
              <Route path="/absences/:id/modifier" element={<AbsenceFormPage />} />

              <Route path="/sanctions" element={<SanctionListPage />} />
              <Route path="/sanctions/nouvelle" element={<SanctionFormPage />} />
              <Route path="/sanctions/:id/modifier" element={<SanctionFormPage />} />

              <Route path="/retards" element={<RetardListPage />} />
              <Route path="/retards/nouveau" element={<RetardFormPage />} />
              <Route path="/retards/:id/modifier" element={<RetardFormPage />} />

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

              <Route path="/documents-eleves" element={<DocumentsElevesPage />} />
              <Route path="/documents-enseignants" element={<DocumentsEnseignantsPage />} />
              <Route path="/documents-etablissement" element={<DocumentsEtablissementPage />} />

              <Route path="/inscriptions" element={<InscriptionListPage />} />

              <Route path="/messages" element={<MessagesPage />} />
              <Route path="/annonces" element={<AnnoncesPage />} />

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

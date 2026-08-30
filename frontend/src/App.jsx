import { useEffect, useState } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import ProtectedRoute from './routes/ProtectedRoute'
import Layout from './components/layout/Layout'
import LoginPage from './features/auth/LoginPage'
import DashboardPage from './features/dashboard/DashboardPage'
import EleveListPage from './features/eleves/EleveListPage'
import EleveFormPage from './features/eleves/EleveFormPage'
import ClasseListPage from './features/classes/ClasseListPage'
import ClasseFormPage from './features/classes/ClasseFormPage'
import EnseignantListPage from './features/enseignants/EnseignantListPage'
import EnseignantFormPage from './features/enseignants/EnseignantFormPage'
import MatiereListPage from './features/matieres/MatiereListPage'
import MatiereFormPage from './features/matieres/MatiereFormPage'
import NoteListPage from './features/notes/NoteListPage'
import NoteFormPage from './features/notes/NoteFormPage'
import AbsenceListPage from './features/absences/AbsenceListPage'
import AbsenceFormPage from './features/absences/AbsenceFormPage'
import SanctionListPage from './features/sanctions/SanctionListPage'
import SanctionFormPage from './features/sanctions/SanctionFormPage'
import ParentListPage from './features/parents/ParentListPage'
import ParentFormPage from './features/parents/ParentFormPage'
import SeanceListPage from './features/seances/SeanceListPage'
import SeanceFormPage from './features/seances/SeanceFormPage'
import NiveauListPage from './features/niveaux/NiveauListPage'
import AnneeScolaireListPage from './features/annees-scolaires/AnneeScolaireListPage'
import RetardListPage from './features/retards/RetardListPage'
import RetardFormPage from './features/retards/RetardFormPage'
import MoyennesPage from './features/rapports/MoyennesPage'
import AssiduitePage from './features/rapports/AssiduitePage'
import EvaluationListPage from './features/rapports/EvaluationListPage'
import ProgrammeListPage from './features/programmes/ProgrammeListPage'
import ProgrammeFormPage from './features/programmes/ProgrammeFormPage'
import RessourceListPage from './features/ressources/RessourceListPage'
import DocumentsElevesPage from './features/documents/DocumentsElevesPage'
import DocumentsEtablissementPage from './features/documents/DocumentsEtablissementPage'
import InscriptionListPage from './features/inscriptions/InscriptionListPage'
import MessagesPage from './features/communication/MessagesPage'
import AnnoncesPage from './features/communication/AnnoncesPage'
import ConseilListPage from './features/conseils/ConseilListPage'
import ConseilDetailPage from './features/conseils/ConseilDetailPage'
import AdminLayout from './components/layout/AdminLayout'
import AdminDashboardPage from './features/admin/AdminDashboardPage'
import SocieteListPage from './features/admin/SocieteListPage'
import EtablissementListPage from './features/admin/EtablissementListPage'
import UtilisateurListPage from './features/admin/UtilisateurListPage'
import UtilisateurDetailPage from './features/admin/UtilisateurDetailPage'
import JournalActivitePage from './features/admin/JournalActivitePage'
import { useAuthStore } from './store/authStore'
import { ROLES } from './lib/constants'
import { fetchMe } from './features/auth/authApi'

const queryClient = new QueryClient()

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
            <Route path="/documents-etablissement" element={<DocumentsEtablissementPage />} />

            <Route path="/inscriptions" element={<InscriptionListPage />} />

            <Route path="/messages" element={<MessagesPage />} />
            <Route path="/annonces" element={<AnnoncesPage />} />

            <Route path="/conseils-classe" element={<ConseilListPage />} />
            <Route path="/conseils-classe/:id" element={<ConseilDetailPage />} />
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

import { Link } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import BandeauPortail from '../../components/ui/BandeauPortail'
import ListeJustificatifs from '../justificatifs/ListeJustificatifs'
import { apiJustificatifsEnseignant } from './portailEnseignantApi'

/** Espace Enseignant : les justificatifs envoyés par les parents de ses classes. */
export default function JustificatifsEnseignantPage() {
  return (
    <div>
      <Link to="/mon-espace" className="mb-4 inline-flex items-center gap-1.5 text-sm font-bold text-muted hover:text-heading">
        <ArrowLeft size={15} strokeWidth={2.5} /> Accueil
      </Link>
      <div className="mb-5">
        <BandeauPortail titre="Justificatifs d’absence" sousTitre="Envoyés par les parents de vos classes." />
      </div>
      <ListeJustificatifs api={apiJustificatifsEnseignant} cleRequete="justificatifs-enseignant" />
    </div>
  )
}

import { useState } from 'react'
import { AlertTriangle, Check, Copy, KeyRound } from 'lucide-react'
import Button from './Button'
import useEchap from '../../hooks/useEchap'

/**
 * Identifiants d'un accès qui vient d'être ouvert (parent à l'inscription, enseignant à
 * la création de sa fiche). Le mot de passe n'existe qu'ici : il n'est jamais stocké en
 * clair, donc cette fenêtre est la seule occasion de le lire et de le transmettre.
 *
 * `acces` reprend la réponse du serveur : { nouveau, login, mot_de_passe, nom, role,
 * desactive, enfant_rattache } ou { erreur }.
 */
export default function ModaleAcces({ acces, onFermer }) {
  const [copie, setCopie] = useState(false)
  useEchap(onFermer)

  if (!acces) return null

  const copier = async () => {
    const texte = acces.mot_de_passe
      ? `Identifiant : ${acces.login}\nMot de passe : ${acces.mot_de_passe}`
      : `Identifiant : ${acces.login}`
    try {
      await navigator.clipboard.writeText(texte)
      setCopie(true)
      window.setTimeout(() => setCopie(false), 2500)
    } catch {
      // Presse-papiers refusé par le navigateur : les identifiants restent lisibles à l'écran.
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onFermer}>
      <div className="w-full max-w-md rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        {acces.erreur ? (
          <>
            <h2 className="mb-2 flex items-center gap-2 text-lg font-bold text-slate-800">
              <AlertTriangle size={18} className="text-amber-500" /> Accès non créé
            </h2>
            <p className="text-sm text-slate-600">{acces.erreur}</p>
            <p className="mt-2 text-xs text-slate-400">
              L'enregistrement, lui, a bien été fait. L'accès pourra être créé plus tard depuis
              Configuration administrative → Utilisateurs.
            </p>
          </>
        ) : (
          <>
            <h2 className="mb-1 flex items-center gap-2 text-lg font-bold text-slate-800">
              <KeyRound size={18} className="text-primary-600" />
              {acces.nouveau ? `Accès ${(acces.role ?? '').toLowerCase()} créé` : 'Compte déjà existant'}
            </h2>
            <p className="mb-4 text-sm text-slate-500">{acces.nom}</p>

            {!acces.nouveau && (
              <p className="mb-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                {acces.enfant_rattache
                  ? 'Cette personne avait déjà un compte : l’enfant y a simplement été rattaché. Son mot de passe est inchangé.'
                  : 'Cette personne avait déjà un compte. Son mot de passe est inchangé.'}
              </p>
            )}

            <dl className="space-y-3 rounded-xl border border-slate-200 p-4">
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">Identifiant</dt>
                <dd className="font-mono text-lg text-slate-800">{acces.login}</dd>
              </div>
              {acces.mot_de_passe && (
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">Mot de passe</dt>
                  <dd className="font-mono text-lg tracking-widest text-slate-800">{acces.mot_de_passe}</dd>
                </div>
              )}
            </dl>

            {acces.mot_de_passe && (
              <p className="mt-3 flex items-start gap-1.5 text-xs text-amber-700">
                <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                Notez-le et communiquez-le maintenant : il n’est pas conservé et ne sera plus jamais affiché.
              </p>
            )}

            {acces.desactive && (
              <p className="mt-3 flex items-start gap-1.5 text-xs text-red-600">
                <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                Ce compte est désactivé : son titulaire ne pourra pas se connecter tant qu’un
                administrateur ne l’aura pas réactivé.
              </p>
            )}
          </>
        )}

        <div className="mt-5 flex justify-end gap-2">
          {!acces.erreur && (
            <Button variant="outline" onClick={copier}>
              {copie ? <Check size={15} className="mr-1.5 inline" /> : <Copy size={15} className="mr-1.5 inline" />}
              {copie ? 'Copié' : 'Copier'}
            </Button>
          )}
          <Button onClick={onFermer}>J’ai noté</Button>
        </div>
      </div>
    </div>
  )
}

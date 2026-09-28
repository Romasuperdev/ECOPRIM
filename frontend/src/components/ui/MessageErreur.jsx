import { Lock, ServerCrash } from 'lucide-react'

/**
 * Ce qu'un écran affiche quand ses données n'ont pas pu être chargées.
 *
 * POURQUOI CE COMPOSANT. Le menu masque déjà les entrées qu'un compte n'a pas le droit
 * d'ouvrir, mais rien n'empêche d'arriver par l'adresse directe, ni le serveur de tomber.
 * Une page qui, dans ce cas, continue de lire des données absentes se termine en écran
 * cassé — l'utilisateur voit « Cet écran n'a pas pu s'afficher » et n'apprend rien.
 *
 * Un refus (403) doit se lire comme un refus, et nommer la permission qui manque : c'est
 * ce qu'on va demander à son administrateur.
 */
export default function MessageErreur({ erreur, permission }) {
  if (!erreur) return null

  const statut = erreur?.response?.status
  const refus = statut === 403
  const message = erreur?.response?.data?.message

  return (
    <div className="card rounded-2xl px-5 py-8 text-center">
      <div className="mb-3 flex justify-center" style={{ color: refus ? 'var(--warning)' : 'var(--danger)' }}>
        {refus ? <Lock size={28} strokeWidth={2.5} /> : <ServerCrash size={28} strokeWidth={2.5} />}
      </div>

      <h2 className="text-lg font-bold text-heading">
        {refus ? 'Accès refusé' : 'Données indisponibles'}
      </h2>

      <p className="mx-auto mt-2 max-w-xl text-sm font-medium text-muted">
        {refus ? (
          <>
            Votre compte n’a pas le droit d’ouvrir cet écran
            {permission && <> : il demande la permission <strong>« {permission} »</strong></>}.
            Demandez-la à votre administrateur — elle s’accorde depuis{' '}
            <strong>Configuration administrative → Permissions</strong> — ou connectez-vous
            avec un compte d’administration.
          </>
        ) : (
          message
            ?? 'Le serveur n’a pas répondu comme attendu. Réessayez, et prévenez votre administrateur si cela persiste.'
        )}
      </p>

      {!refus && statut && (
        <p className="mt-2 text-xs font-semibold text-muted">Code {statut}</p>
      )}
    </div>
  )
}

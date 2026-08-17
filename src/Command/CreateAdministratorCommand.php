<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the first administrator, or promotes an existing account.
 *
 * Access has to exist before the interface that grants it. This is the smallest
 * thing that can bootstrap it — smaller than seeding a migration, which would
 * ship a known credential to every environment that ran it, and unlike the
 * fixtures, which are development-only and purge the database.
 *
 * It hands out administrative access, so it is only usable by somebody who
 * already has shell access to the machine. That is the intended audience.
 */
#[AsCommand(
    name: 'app:create-administrator',
    description: 'Create an administrator account, or promote and re-password an existing one',
)]
final class CreateAdministratorCommand extends Command
{
    /**
     * Short enough not to be a nuisance, long enough that the command is not the
     * weak point. Anything stronger is a policy decision this project has not
     * made, and pretending otherwise with a complexity rule would be theatre.
     */
    private const int MINIMUM_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email address to sign in with')
            ->addArgument('password', InputArgument::REQUIRED, 'The password to set')
            ->addArgument('displayName', InputArgument::OPTIONAL, 'The name shown as an author byline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = trim((string) $input->getArgument('email'));
        $password = (string) $input->getArgument('password');
        $displayName = trim((string) $input->getArgument('displayName'));

        if ('' === $email) {
            $io->error('An email address is required.');

            return Command::INVALID;
        }

        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $io->error(sprintf('The password must be at least %d characters.', self::MINIMUM_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        // Promote rather than duplicate. Running this twice for the same address
        // is what somebody does when they have forgotten the password, and
        // failing on a unique-constraint violation would be an unhelpful answer
        // to that.
        $user = $this->users->findOneByEmail($email);
        $created = !$user instanceof User;

        if (!$user instanceof User) {
            $user = new User(
                $email,
                '' === $displayName ? $email : $displayName,
                new DateTimeImmutable(),
            );
            $this->entityManager->persist($user);
        } elseif ('' !== $displayName) {
            $user->setDisplayName($displayName);
        }

        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->flush();

        // The password is never echoed, not even back to the person who just
        // typed it. It is already in their shell history; it does not need to be
        // in a log as well.
        $io->success(sprintf(
            '%s %s as an administrator.',
            $created ? 'Created' : 'Promoted',
            $email,
        ));

        return Command::SUCCESS;
    }
}
